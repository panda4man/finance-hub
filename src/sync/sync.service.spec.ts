import { drizzle } from 'drizzle-orm/node-postgres';
import { migrate } from 'drizzle-orm/node-postgres/migrator';
import { desc, eq, inArray } from 'drizzle-orm';
import { Pool } from 'pg';
import * as schema from '../db/schema';
import { users, connections, accounts, categories, categoryRules, transactions, syncRuns } from '../db/schema';
import { SyncService } from './sync.service';
import { ConnectionsService } from '../connections/connections.service';
import { CategorizationService } from '../categorization/categorization.service';
import { CryptoService } from '../crypto/crypto.service';
import { SimplefinApiError } from '../simplefin/simplefin-error';
import type { SimplefinService } from '../simplefin/simplefin.service';
import type { ProviderSyncPage } from '../simplefin/simplefin.types';

const TEST_DATABASE_URL =
  process.env.TEST_DATABASE_URL ?? 'postgres://finance:finance@localhost:55432/finance_hub';
const TEST_ENCRYPTION_KEY = Buffer.alloc(32, 7).toString('base64');

describe('SyncService (integration)', () => {
  let pool: Pool;
  let db: ReturnType<typeof drizzle<typeof schema>>;
  let crypto: CryptoService;
  let connectionId: string;
  let accountExternalId: string;

  beforeAll(async () => {
    pool = new Pool({ connectionString: TEST_DATABASE_URL });
    db = drizzle(pool, { schema });
    await migrate(db, { migrationsFolder: './drizzle' });
    crypto = new CryptoService({ get: () => TEST_ENCRYPTION_KEY } as never);
  });

  afterAll(async () => {
    await pool.end();
  });

  beforeEach(async () => {
    // Clean slate for each test.
    await db.delete(transactions);
    await db.delete(syncRuns);
    await db.delete(accounts);
    await db.delete(connections);
    await db.delete(users);
    await db.delete(categoryRules);
    await db.delete(categories);

    const [user] = await db.insert(users).values({}).returning({ id: users.id });
    const [connection] = await db
      .insert(connections)
      .values({
        userId: user.id,
        provider: 'simplefin',
        credentialEncrypted: crypto.encrypt('test-access-url'),
        status: 'active',
      })
      .returning({ id: connections.id });
    connectionId = connection.id;

    accountExternalId = `test-account-${Date.now()}`;
    await db.insert(accounts).values({
      connectionId,
      externalAccountId: accountExternalId,
      name: 'Test Checking',
    });
  });

  async function makeService(fetchAccountSetMock: jest.Mock) {
    const fakeSimplefin = { fetchAccountSet: fetchAccountSetMock } as unknown as SimplefinService;
    const connectionsService = new ConnectionsService(db as never, crypto, fakeSimplefin);
    const categorization = new CategorizationService(db as never);
    await categorization.reloadCache();
    return new SyncService(db as never, connectionsService, fakeSimplefin, categorization);
  }

  function basePage(overrides: Partial<ProviderSyncPage['accounts'][number]['transactions'][number]> = {}): ProviderSyncPage {
    return {
      institutions: [],
      accounts: [
        {
          externalAccountId: accountExternalId,
          externalOrgId: 'org-1',
          name: 'Test Checking',
          isoCurrencyCode: 'USD',
          transactions: [
            {
              externalTransactionId: 'txn-1',
              date: '2026-07-10',
              amount: '10.00',
              name: 'Coffee Shop',
              pending: false,
              raw: {},
              ...overrides,
            },
          ],
        },
      ],
    };
  }

  it('upserts accounts and transactions on first sync, leaving categoryId unset when no rule matches', async () => {
    const fetchMock = jest.fn().mockResolvedValueOnce(basePage());
    const service = await makeService(fetchMock);

    const result = await service.syncConnection(connectionId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(txn.amount).toBe('10.00');
    expect(txn.categoryId).toBeNull();

    const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
    expect(connection.status).toBe('active');
    expect(connection.lastSuccessfulSyncAt).not.toBeNull();
  });

  it('sets categoryId from a matching category rule on first sync', async () => {
    const [category] = await db
      .insert(categories)
      .values({ slug: `coffee_test_${Date.now()}`, name: 'Coffee' })
      .returning({ id: categories.id });
    await db.insert(categoryRules).values({ pattern: 'coffee shop', categoryId: category.id });

    const fetchMock = jest.fn().mockResolvedValueOnce(basePage());
    const service = await makeService(fetchMock);

    const result = await service.syncConnection(connectionId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(txn.categoryId).toBe(category.id);
  });

  it('updates provider-controlled fields on a later sync but never overwrites user-owned fields', async () => {
    const firstFetch = jest.fn().mockResolvedValueOnce(basePage());
    await (await makeService(firstFetch)).syncConnection(connectionId, 'manual');

    // Simulate the user categorizing/annotating/hiding this transaction.
    const [category] = await db
      .insert(categories)
      .values({ slug: `custom_test_${Date.now()}`, name: 'Custom', kind: 'custom' })
      .returning({ id: categories.id });
    await db
      .update(transactions)
      .set({ userCategoryId: category.id, userNotes: 'my custom note', isHidden: true })
      .where(eq(transactions.externalTransactionId, 'txn-1'));

    const secondFetch = jest
      .fn()
      .mockResolvedValueOnce(basePage({ amount: '12.50', name: 'Coffee Shop Updated' }));
    const result = await (await makeService(secondFetch)).syncConnection(connectionId, 'manual');
    expect(result.status).toBe('success');

    const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(txn.amount).toBe('12.50');
    expect(txn.name).toBe('Coffee Shop Updated');
    // user-owned fields survive the update untouched
    expect(txn.userCategoryId).toBe(category.id);
    expect(txn.userNotes).toBe('my custom note');
    expect(txn.isHidden).toBe(true);
  });

  it('recomputes categoryId on a re-sync (conflict-update path) without touching userCategoryId', async () => {
    const [ruleCategory] = await db
      .insert(categories)
      .values({ slug: `coffee_resync_${Date.now()}`, name: 'Coffee' })
      .returning({ id: categories.id });
    await db.insert(categoryRules).values({ pattern: 'coffee shop', categoryId: ruleCategory.id });

    const firstFetch = jest.fn().mockResolvedValueOnce(basePage());
    await (await makeService(firstFetch)).syncConnection(connectionId, 'manual');

    const [userCategory] = await db
      .insert(categories)
      .values({ slug: `custom_resync_${Date.now()}`, name: 'Custom', kind: 'custom' })
      .returning({ id: categories.id });
    await db
      .update(transactions)
      .set({ userCategoryId: userCategory.id })
      .where(eq(transactions.externalTransactionId, 'txn-1'));

    const secondFetch = jest.fn().mockResolvedValueOnce(basePage({ name: 'Coffee Shop Updated' }));
    await (await makeService(secondFetch)).syncConnection(connectionId, 'manual');

    const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(txn.categoryId).toBe(ruleCategory.id);
    expect(txn.userCategoryId).toBe(userCategory.id);
  });

  it('creates no rows and records a failed sync run when the fetch fails', async () => {
    const fetchMock = jest.fn().mockRejectedValueOnce(new Error('boom'));
    const service = await makeService(fetchMock);

    await expect(service.syncConnection(connectionId, 'manual')).rejects.toBeDefined();

    const rows = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'txn-1'));
    expect(rows).toHaveLength(0);

    const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
    expect(connection.lastSuccessfulSyncAt).toBeNull();

    const [run] = await db.select().from(syncRuns).where(eq(syncRuns.connectionId, connectionId));
    expect(run.status).toBe('failed');
  });

  it('marks the connection login_required on an auth error, without advancing lastSuccessfulSyncAt', async () => {
    const fetchMock = jest
      .fn()
      .mockRejectedValueOnce(new SimplefinApiError(403, [{ code: 'con.auth', msg: 're-auth needed' }]));
    const service = await makeService(fetchMock);

    await expect(service.syncConnection(connectionId, 'manual')).rejects.toBeDefined();

    const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
    expect(connection.status).toBe('login_required');
    expect(connection.lastSuccessfulSyncAt).toBeNull();
  });

  describe('backfillConnection', () => {
    function pageWithTransactions(
      txns: { externalTransactionId: string; date: string; amount?: string; name?: string }[],
    ): ProviderSyncPage {
      return {
        institutions: [],
        accounts: [
          {
            externalAccountId: accountExternalId,
            externalOrgId: 'org-1',
            name: 'Test Checking',
            isoCurrencyCode: 'USD',
            transactions: txns.map((t) => ({
              externalTransactionId: t.externalTransactionId,
              date: t.date,
              amount: t.amount ?? '10.00',
              name: t.name ?? 'Coffee Shop',
              pending: false,
              raw: {},
            })),
          },
        ],
      };
    }

    function emptyPage(): ProviderSyncPage {
      return pageWithTransactions([]);
    }

    it('walks backward and stops after 3 consecutive empty windows', async () => {
      const fetchMock = jest
        .fn()
        .mockResolvedValueOnce(pageWithTransactions([{ externalTransactionId: 'bf-1', date: '2026-07-01' }]))
        .mockResolvedValue(emptyPage());
      const service = await makeService(fetchMock);

      const result = await service.backfillConnection(connectionId);
      expect(result.status).toBe('success');
      if (result.status !== 'success') {
        throw new Error('unreachable');
      }
      expect(result.pagesFetched).toBe(4);
      expect(result.added).toBe(1);
      expect(fetchMock).toHaveBeenCalledTimes(4);

      const [txn] = await db.select().from(transactions).where(eq(transactions.externalTransactionId, 'bf-1'));
      expect(txn).toBeDefined();
    });

    it('does not stop on a single dormant window if an older window still has transactions', async () => {
      const fetchMock = jest
        .fn()
        .mockResolvedValueOnce(pageWithTransactions([{ externalTransactionId: 'bf-recent', date: '2026-07-01' }]))
        .mockResolvedValueOnce(emptyPage())
        .mockResolvedValueOnce(pageWithTransactions([{ externalTransactionId: 'bf-older', date: '2026-01-01' }]))
        .mockResolvedValue(emptyPage());
      const service = await makeService(fetchMock);

      const result = await service.backfillConnection(connectionId);
      expect(result.status).toBe('success');
      if (result.status !== 'success') {
        throw new Error('unreachable');
      }
      expect(result.added).toBe(2);

      const rows = await db
        .select()
        .from(transactions)
        .where(inArray(transactions.externalTransactionId, ['bf-recent', 'bf-older']));
      expect(rows).toHaveLength(2);
    });

    it('sets lastSuccessfulSyncAt on a natural stop when it was previously null', async () => {
      const fetchMock = jest.fn().mockResolvedValue(emptyPage());
      const service = await makeService(fetchMock);

      const result = await service.backfillConnection(connectionId);
      expect(result.status).toBe('success');

      const [connection] = await db.select().from(connections).where(eq(connections.id, connectionId));
      expect(connection.lastSuccessfulSyncAt).not.toBeNull();
    });

    it('leaves lastSuccessfulSyncAt untouched when it was already set', async () => {
      const firstFetch = jest.fn().mockResolvedValueOnce(basePage());
      await (await makeService(firstFetch)).syncConnection(connectionId, 'manual');
      const [afterSync] = await db.select().from(connections).where(eq(connections.id, connectionId));
      const syncedAt = afterSync.lastSuccessfulSyncAt!.getTime();

      const backfillFetch = jest.fn().mockResolvedValue(emptyPage());
      const result = await (await makeService(backfillFetch)).backfillConnection(connectionId);
      expect(result.status).toBe('success');

      const [afterBackfill] = await db.select().from(connections).where(eq(connections.id, connectionId));
      expect(afterBackfill.lastSuccessfulSyncAt!.getTime()).toBe(syncedAt);
    });

    it('preserves transactions from windows already committed when a later window fails', async () => {
      const fetchMock = jest
        .fn()
        .mockResolvedValueOnce(pageWithTransactions([{ externalTransactionId: 'bf-committed', date: '2026-07-01' }]))
        .mockRejectedValueOnce(new Error('boom'));
      const service = await makeService(fetchMock);

      await expect(service.backfillConnection(connectionId)).rejects.toBeDefined();

      const [txn] = await db
        .select()
        .from(transactions)
        .where(eq(transactions.externalTransactionId, 'bf-committed'));
      expect(txn).toBeDefined();

      const [run] = await db
        .select()
        .from(syncRuns)
        .where(eq(syncRuns.connectionId, connectionId))
        .orderBy(desc(syncRuns.startedAt));
      expect(run.status).toBe('failed');
      expect(run.trigger).toBe('backfill');
    });
  });
});
