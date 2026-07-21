/**
 * One-off import of banking_dashboard.sql — a MySQL dump of an old
 * self-built Plaid dashboard (2019-era). Only `items.user_id = 1` (the
 * user's own Chase + Ally connections) is imported; every other row in the
 * dump belongs to someone else and is left untouched. Imported as frozen,
 * unlinked history: `connections.status = 'revoked'`, no credential stored,
 * never picked up by SyncScheduler. Run once: `npm run import:legacy-dump`.
 */
import { createReadStream } from 'fs';
import { createInterface } from 'readline';
import { drizzle } from 'drizzle-orm/node-postgres';
import { Pool } from 'pg';
import { users, institutions, connections, accounts, transactions } from './schema';

const DUMP_PATH = process.env.LEGACY_DUMP_PATH ?? 'banking_dashboard.sql';
const TARGET_USER_ID = '1'; // banking_dashboard.sql's own user id, not this app's
const PROVIDER = 'plaid_archive';

type Row = (string | null)[];

/** Unescapes a mysqldump single-quoted string body (\\, \', \", \n, \r, \0, \Z). */
function unescapeMysqlString(s: string): string {
  let out = '';
  for (let i = 0; i < s.length; i++) {
    if (s[i] === '\\') {
      const next = s[i + 1];
      i++;
      switch (next) {
        case 'n':
          out += '\n';
          break;
        case 'r':
          out += '\r';
          break;
        case 't':
          out += '\t';
          break;
        case '0':
          out += '\0';
          break;
        case 'Z':
          out += '\x1a';
          break;
        default:
          out += next;
      }
    } else {
      out += s[i];
    }
  }
  return out;
}

function splitFields(tuple: string): string[] {
  const fields: string[] = [];
  let field = '';
  let inString = false;
  for (let i = 0; i < tuple.length; i++) {
    const ch = tuple[i];
    if (inString) {
      if (ch === '\\') {
        field += ch + tuple[i + 1];
        i++;
        continue;
      }
      field += ch;
      if (ch === "'") inString = false;
      continue;
    }
    if (ch === "'") {
      inString = true;
      field += ch;
      continue;
    }
    if (ch === ',') {
      fields.push(field.trim());
      field = '';
      continue;
    }
    field += ch;
  }
  fields.push(field.trim());
  return fields;
}

function parseValue(raw: string): string | null {
  if (raw === 'NULL') return null;
  if (raw.startsWith("'") && raw.endsWith("'")) {
    return unescapeMysqlString(raw.slice(1, -1));
  }
  return raw;
}

/**
 * Parses every tuple out of a single `INSERT INTO \`table\` VALUES (...),(...);`
 * statement. mysqldump emits each statement as one physical line (string
 * literals use `\n` escapes, never raw newlines), so the whole dump can be
 * processed line-by-line via a stream without ever holding the full
 * (1GB+, mostly other users' institution logos) file in memory at once.
 */
function parseInsertLine(line: string, table: string): Row[] {
  const prefix = `INSERT INTO \`${table}\` VALUES `;
  const rows: Row[] = [];
  let i = prefix.length;
  for (;;) {
    while (line[i] === ' ' || line[i] === ',') i++;
    if (line[i] === ';') break;
    if (line[i] !== '(') {
      throw new Error(`Malformed dump: expected '(' for table ${table} at offset ${i}`);
    }
    const tupleStart = i + 1;
    i++;
    let depth = 1;
    let inString = false;
    while (depth > 0) {
      const ch = line[i];
      if (inString) {
        if (ch === '\\') {
          i += 2;
          continue;
        }
        if (ch === "'") inString = false;
        i++;
        continue;
      }
      if (ch === "'") inString = true;
      else if (ch === '(') depth++;
      else if (ch === ')') depth--;
      i++;
    }
    rows.push(splitFields(line.slice(tupleStart, i - 1)).map(parseValue));
  }
  return rows;
}

/**
 * Streams the dump line-by-line, keeping only rows from the requested
 * tables that pass each table's filter — the dump is well over a gigabyte
 * (mostly other users' data and Plaid's global institution-logo directory),
 * so rows are discarded as they're read rather than ever collected in bulk.
 * Table data order in the dump doesn't match FK dependency order, so a full
 * import needs multiple passes (see `main`): one to learn which
 * items/institutions/accounts are relevant, then a pass that can filter
 * inline using that knowledge.
 */
async function collectFilteredRows(
  path: string,
  filters: Record<string, (row: Row) => boolean>,
): Promise<Record<string, Row[]>> {
  const prefixes = Object.keys(filters).map((t) => [`INSERT INTO \`${t}\` VALUES `, t] as const);
  const result: Record<string, Row[]> = {};
  for (const t of Object.keys(filters)) result[t] = [];

  const rl = createInterface({ input: createReadStream(path, { encoding: 'utf8' }), crlfDelay: Infinity });
  for await (const line of rl) {
    for (const [prefix, table] of prefixes) {
      if (line.startsWith(prefix)) {
        for (const row of parseInsertLine(line, table)) {
          if (filters[table](row)) result[table].push(row);
        }
        break;
      }
    }
  }
  return result;
}

function tryParseJson(raw: string | null): unknown {
  if (!raw) return null;
  try {
    return JSON.parse(raw);
  } catch {
    return raw;
  }
}

async function main() {
  const connectionString = process.env.DATABASE_URL;
  if (!connectionString) {
    throw new Error('DATABASE_URL is required to import the legacy dump');
  }

  // Column order matches each table's CREATE TABLE statement in the dump.
  // Pass 1: `items` is tiny (~11 rows) — grab all of them, then learn which
  // institutions/accounts we actually need from the ones owned by our user.
  const { items: allItemRows } = await collectFilteredRows(DUMP_PATH, { items: () => true });
  const itemRows = allItemRows.filter((r) => r[1] === TARGET_USER_ID);
  const itemIds = new Set(itemRows.map((r) => r[0]!));
  const institutionIdsNeeded = new Set(itemRows.map((r) => r[3]).filter((v): v is string => v !== null));

  // Pass 2: institutions/accounts data appears *before* items in the dump,
  // so this can't be filtered inline during pass 1 — needs its own pass now
  // that itemIds/institutionIdsNeeded are known.
  const { institutions: institutionRows, accounts: accountRows } = await collectFilteredRows(DUMP_PATH, {
    institutions: (r) => institutionIdsNeeded.has(r[0]!),
    accounts: (r) => itemIds.has(r[1]!),
  });
  const accountIds = new Set(accountRows.map((r) => r[0]!));

  // Pass 3: transactions appears after items, but accountIds is only known now.
  const { transactions: transactionRows } = await collectFilteredRows(DUMP_PATH, {
    transactions: (r) => accountIds.has(r[1]!),
  });

  console.log(
    `Parsed: ${itemRows.length} item(s), ${institutionRows.length} institution(s), ` +
      `${accountRows.length} account(s), ${transactionRows.length} transaction(s)`,
  );

  const pool = new Pool({ connectionString });
  const db = drizzle(pool, { schema: { users, institutions, connections, accounts, transactions } });

  const [user] = await db.select({ id: users.id }).from(users).limit(1);
  if (!user) {
    throw new Error('No user row found; run `npm run db:migrate` first');
  }

  // institutions: [id, name, plaid_institution_id, has_mfa, mfa_code_type, mfa, url, image_path, logo, primary_color, ...]
  const institutionIdByLegacyId = new Map<string, string>();
  for (const row of institutionRows) {
    const [legacyId, name, plaidInstitutionId, , , , url, , , primaryColor] = row;
    const [dbRow] = await db
      .insert(institutions)
      .values({
        provider: PROVIDER,
        externalOrgId: plaidInstitutionId ?? legacyId!,
        name: name ?? 'Unknown institution',
        url: url ?? undefined,
        primaryColor: primaryColor ?? undefined,
      })
      .onConflictDoUpdate({
        target: [institutions.provider, institutions.externalOrgId],
        set: { name: name ?? 'Unknown institution', updatedAt: new Date() },
      })
      .returning({ id: institutions.id });
    institutionIdByLegacyId.set(legacyId!, dbRow.id);
  }

  // items: [id, user_id, plaid_item_id, institution_id, access_token, ...] — access_token is a
  // years-dead Plaid dev-environment token; deliberately not imported.
  const connectionIdByLegacyItemId = new Map<string, string>();
  for (const row of itemRows) {
    const [legacyId, , plaidItemId, legacyInstitutionId] = row;
    const [dbRow] = await db
      .insert(connections)
      .values({
        userId: user.id,
        provider: PROVIDER,
        credentialEncrypted: null,
        status: 'revoked',
        statusDetail: `Imported from legacy dump (plaid_item_id=${plaidItemId})`,
      })
      .returning({ id: connections.id });
    connectionIdByLegacyItemId.set(legacyId!, dbRow.id);
  }

  // accounts: [id, item_id, plaid_account_id, mask, name, official_name, subtype, type, balances, ...] —
  // `balances` is Laravel-encrypted with an APP_KEY we don't have; not recoverable, left null.
  const accountIdByLegacyId = new Map<string, string>();
  const connectionIdByLegacyAccountId = new Map<string, string>();
  for (const row of accountRows) {
    const [legacyId, legacyItemId, plaidAccountId, mask, name, officialName, subtype, type] = row;
    const legacyInstitutionId = itemRows.find((r) => r[0] === legacyItemId)?.[3] ?? undefined;
    const connectionId = connectionIdByLegacyItemId.get(legacyItemId!)!;
    const [dbRow] = await db
      .insert(accounts)
      .values({
        connectionId,
        institutionId: legacyInstitutionId ? institutionIdByLegacyId.get(legacyInstitutionId) : undefined,
        externalAccountId: plaidAccountId!,
        name: name ?? 'Unknown account',
        officialName: officialName ?? undefined,
        mask: mask ?? undefined,
        type: type ?? undefined,
        subtype: subtype ?? undefined,
      })
      .onConflictDoNothing({ target: accounts.externalAccountId })
      .returning({ id: accounts.id });
    if (dbRow) {
      accountIdByLegacyId.set(legacyId!, dbRow.id);
      connectionIdByLegacyAccountId.set(legacyId!, connectionId);
    }
  }

  // transactions: [id, account_id, transaction_id, category_id, pending_transaction_id, name, amount,
  //   pending, transaction_type, iso_currency_code, unofficial_currency_code, account_owner, date,
  //   plaid_category, location, payment_meta, created_at, updated_at, deleted_at]
  const BATCH_SIZE = 500;
  let imported = 0;
  for (let i = 0; i < transactionRows.length; i += BATCH_SIZE) {
    const batch = transactionRows.slice(i, i + BATCH_SIZE);
    const values = batch
      .map((row) => {
        const [
          ,
          legacyAccountId,
          transactionId,
          categoryId,
          pendingTransactionId,
          name,
          amount,
          pending,
          transactionType,
          isoCurrencyCode,
          ,
          accountOwner,
          date,
          plaidCategory,
          location,
          paymentMeta,
        ] = row;
        const accountId = accountIdByLegacyId.get(legacyAccountId!);
        const connectionId = connectionIdByLegacyAccountId.get(legacyAccountId!);
        if (!accountId || !connectionId) return null;
        return {
          accountId,
          connectionId,
          externalTransactionId: transactionId!,
          pending: pending === '1',
          amount: amount!,
          isoCurrencyCode: isoCurrencyCode ?? undefined,
          date: date!,
          name: name ?? '(no description)',
          rawPayload: {
            legacyCategoryId: categoryId,
            legacyPendingTransactionId: pendingTransactionId,
            transactionType,
            accountOwner,
            plaidCategory: tryParseJson(plaidCategory),
            location: tryParseJson(location),
            paymentMeta: tryParseJson(paymentMeta),
          },
        };
      })
      .filter((v): v is NonNullable<typeof v> => v !== null);

    if (values.length > 0) {
      await db.insert(transactions).values(values).onConflictDoNothing({
        target: transactions.externalTransactionId,
      });
    }
    imported += values.length;
  }

  console.log(`Imported: ${institutionIdByLegacyId.size} institution(s), ${connectionIdByLegacyItemId.size} connection(s), ` +
    `${accountIdByLegacyId.size} account(s), ${imported} transaction(s)`);

  await pool.end();
}

main().catch((err) => {
  console.error('Legacy dump import failed:', err);
  process.exit(1);
});
