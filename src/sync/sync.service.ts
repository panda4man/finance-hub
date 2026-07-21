import { Inject, Injectable, Logger } from '@nestjs/common';
import { eq, inArray, desc, sql, type AnyColumn } from 'drizzle-orm';
import type { Transaction, RemovedTransaction, AccountBase } from 'plaid';
import { DB, Database } from '../db/db.module';
import {
  plaidItems,
  accounts,
  categories,
  transactions,
  transactionCounterparties,
  syncRuns,
  type SyncTrigger,
} from '../db/schema';
import { ItemsService } from '../items/items.service';
import { PlaidService } from '../plaid/plaid.service';
import { getPlaidError, isTransientPlaidError } from '../plaid/plaid-error';

const RETRY_DELAYS_MS = [1000, 4000, 10000];
const ITEM_STATUS_ERROR_CODES: Record<string, string> = {
  ITEM_LOGIN_REQUIRED: 'login_required',
  PENDING_EXPIRATION: 'pending_expiration',
};

type SyncOutcome =
  | {
      itemDbId: string;
      status: 'success';
      pagesFetched: number;
      added: number;
      modified: number;
      removed: number;
      accountsUpserted: number;
    }
  | { itemDbId: string; status: 'failed'; error: string };

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/** References the incoming row's value in an ON CONFLICT DO UPDATE SET clause. */
function excludedValue(column: AnyColumn) {
  return sql`excluded.${sql.identifier(column.name)}`;
}

@Injectable()
export class SyncService {
  private readonly logger = new Logger(SyncService.name);

  constructor(
    @Inject(DB) private readonly db: Database,
    private readonly items: ItemsService,
    private readonly plaid: PlaidService,
  ) {}

  async syncAllActiveItems(trigger: SyncTrigger): Promise<SyncOutcome[]> {
    const activeItems = await this.db
      .select({ id: plaidItems.id })
      .from(plaidItems)
      .where(inArray(plaidItems.status, ['active', 'pending_expiration']));

    const results: SyncOutcome[] = [];
    for (const item of activeItems) {
      results.push(await this.syncItemSafely(item.id, trigger));
    }
    return results;
  }

  async syncItemSafely(itemDbId: string, trigger: SyncTrigger): Promise<SyncOutcome> {
    try {
      return await this.syncItem(itemDbId, trigger);
    } catch (err) {
      const message = (err as Error).message;
      this.logger.error(`Sync failed for item ${itemDbId}: ${message}`);
      return { itemDbId, status: 'failed', error: message };
    }
  }

  async syncItem(itemDbId: string, trigger: SyncTrigger): Promise<SyncOutcome> {
    const [item] = await this.db
      .select()
      .from(plaidItems)
      .where(eq(plaidItems.id, itemDbId))
      .limit(1);
    if (!item) {
      throw new Error(`Plaid item ${itemDbId} not found`);
    }

    const [{ id: runId }] = await this.db
      .insert(syncRuns)
      .values({
        itemId: itemDbId,
        trigger,
        status: 'running',
        cursorBefore: item.transactionsCursor,
      })
      .returning({ id: syncRuns.id });

    this.logger.log(`Starting ${trigger} sync for item ${itemDbId} (run ${runId})`);

    await this.db
      .update(plaidItems)
      .set({ lastAttemptedSyncAt: new Date() })
      .where(eq(plaidItems.id, itemDbId));

    const accessToken = await this.items.decryptAccessToken(itemDbId);
    const categoryMap = await this.loadCategoryMap();

    const counters = { pagesFetched: 0, added: 0, modified: 0, removed: 0, accountsUpserted: 0 };
    let cursor = item.transactionsCursor ?? undefined;

    try {
      await this.db.transaction(async (tx) => {
        let hasMore = true;

        while (hasMore) {
          const page = await this.fetchPageWithRetry(accessToken, cursor);

          if (page.accounts.length > 0) {
            counters.accountsUpserted += await this.upsertAccounts(tx, itemDbId, page.accounts);
          }

          const accountIdMap = await this.loadAccountMap(tx, itemDbId);

          const upserts = [...page.added, ...page.modified];
          if (upserts.length > 0) {
            await this.upsertTransactions(tx, itemDbId, upserts, accountIdMap, categoryMap);
          }
          if (page.removed.length > 0) {
            await this.softDeleteTransactions(tx, page.removed);
          }

          counters.pagesFetched += 1;
          counters.added += page.added.length;
          counters.modified += page.modified.length;
          counters.removed += page.removed.length;

          cursor = page.next_cursor;
          hasMore = page.has_more;
        }

        await tx
          .update(plaidItems)
          .set({
            transactionsCursor: cursor,
            lastSuccessfulSyncAt: new Date(),
            status: 'active',
            statusDetail: null,
          })
          .where(eq(plaidItems.id, itemDbId));

        await tx
          .update(syncRuns)
          .set({
            status: 'success',
            finishedAt: new Date(),
            cursorAfter: cursor,
            pagesFetched: counters.pagesFetched,
            addedCount: counters.added,
            modifiedCount: counters.modified,
            removedCount: counters.removed,
            accountsUpserted: counters.accountsUpserted,
          })
          .where(eq(syncRuns.id, runId));
      });

      this.logger.log(
        `Sync succeeded for item ${itemDbId} (run ${runId}): ` +
          `+${counters.added} added, ${counters.modified} modified, ${counters.removed} removed ` +
          `across ${counters.pagesFetched} page(s)`,
      );
      return { itemDbId, status: 'success', ...counters };
    } catch (err) {
      await this.recordFailure(itemDbId, runId, err);
      throw err;
    }
  }

  private async recordFailure(itemDbId: string, runId: string, err: unknown) {
    const plaidError = getPlaidError(err);
    const errorCode = plaidError?.error_code;
    const errorMessage = plaidError?.error_message ?? (err as Error).message;

    this.logger.error(
      `Sync failed for item ${itemDbId} (run ${runId}): ${errorCode ?? 'UNKNOWN_ERROR'} — ${errorMessage}`,
    );

    const newItemStatus = errorCode ? ITEM_STATUS_ERROR_CODES[errorCode] : undefined;
    if (newItemStatus) {
      this.logger.warn(`Item ${itemDbId} status -> ${newItemStatus} (${errorCode})`);
      await this.db
        .update(plaidItems)
        .set({ status: newItemStatus, statusDetail: errorMessage })
        .where(eq(plaidItems.id, itemDbId));
    }

    await this.db
      .update(syncRuns)
      .set({
        status: 'failed',
        finishedAt: new Date(),
        errorCode,
        errorMessage,
      })
      .where(eq(syncRuns.id, runId));
  }

  private async fetchPageWithRetry(accessToken: string, cursor: string | undefined) {
    for (let attempt = 0; ; attempt += 1) {
      try {
        return await this.plaid.transactionsSync(accessToken, cursor);
      } catch (err) {
        if (attempt >= RETRY_DELAYS_MS.length || !isTransientPlaidError(err)) {
          throw err;
        }
        this.logger.warn(
          `Transient Plaid error on transactions/sync (attempt ${attempt + 1}), retrying in ${RETRY_DELAYS_MS[attempt]}ms`,
        );
        await sleep(RETRY_DELAYS_MS[attempt]);
      }
    }
  }

  /** Latest sync_runs row per item, newest first — "did last night's sync work" at a glance. */
  async getLatestRunsPerItem() {
    const rows = await this.db
      .select()
      .from(syncRuns)
      .orderBy(desc(syncRuns.startedAt));

    const latestByItem = new Map<string, (typeof rows)[number]>();
    for (const row of rows) {
      if (row.itemId && !latestByItem.has(row.itemId)) {
        latestByItem.set(row.itemId, row);
      }
    }
    return [...latestByItem.values()];
  }

  private async loadCategoryMap(): Promise<Map<string, string>> {
    const rows = await this.db
      .select({ id: categories.id, plaidPfcDetailed: categories.plaidPfcDetailed })
      .from(categories)
      .where(eq(categories.kind, 'plaid_pfc'));
    const map = new Map<string, string>();
    for (const row of rows) {
      if (row.plaidPfcDetailed) {
        map.set(row.plaidPfcDetailed, row.id);
      }
    }
    return map;
  }

  private async loadAccountMap(
    tx: Database,
    itemDbId: string,
  ): Promise<Map<string, string>> {
    const rows = await tx
      .select({ id: accounts.id, plaidAccountId: accounts.plaidAccountId })
      .from(accounts)
      .where(eq(accounts.itemId, itemDbId));
    return new Map(rows.map((row) => [row.plaidAccountId, row.id]));
  }

  private async upsertAccounts(
    tx: Database,
    itemDbId: string,
    plaidAccounts: AccountBase[],
  ): Promise<number> {
    await tx
      .insert(accounts)
      .values(
        plaidAccounts.map((account) => ({
          itemId: itemDbId,
          plaidAccountId: account.account_id,
          name: account.name,
          officialName: account.official_name ?? undefined,
          mask: account.mask ?? undefined,
          type: account.type,
          subtype: account.subtype ?? undefined,
          availableBalance: account.balances.available?.toString(),
          currentBalance: account.balances.current?.toString(),
          creditLimit: account.balances.limit?.toString(),
          isoCurrencyCode: account.balances.iso_currency_code ?? undefined,
          balancesUpdatedAt: new Date(),
        })),
      )
      .onConflictDoUpdate({
        target: accounts.plaidAccountId,
        set: {
          name: excludedValue(accounts.name),
          officialName: excludedValue(accounts.officialName),
          mask: excludedValue(accounts.mask),
          type: excludedValue(accounts.type),
          subtype: excludedValue(accounts.subtype),
          availableBalance: excludedValue(accounts.availableBalance),
          currentBalance: excludedValue(accounts.currentBalance),
          creditLimit: excludedValue(accounts.creditLimit),
          isoCurrencyCode: excludedValue(accounts.isoCurrencyCode),
          balancesUpdatedAt: excludedValue(accounts.balancesUpdatedAt),
          updatedAt: new Date(),
        },
      });
    return plaidAccounts.length;
  }

  private async upsertTransactions(
    tx: Database,
    itemDbId: string,
    plaidTransactions: Transaction[],
    accountIdMap: Map<string, string>,
    categoryMap: Map<string, string>,
  ) {
    const rows = plaidTransactions
      .map((t) => {
        const accountId = accountIdMap.get(t.account_id);
        if (!accountId) {
          this.logger.warn(
            `Skipping transaction ${t.transaction_id}: unknown account ${t.account_id}`,
          );
          return null;
        }
        const pfc = t.personal_finance_category;
        return {
          accountId,
          itemId: itemDbId,
          plaidTransactionId: t.transaction_id,
          pending: t.pending,
          pendingPlaidTransactionId: t.pending_transaction_id ?? undefined,
          amount: t.amount.toFixed(2),
          isoCurrencyCode: t.iso_currency_code ?? undefined,
          unofficialCurrencyCode: t.unofficial_currency_code ?? undefined,
          date: t.date,
          authorizedDate: t.authorized_date ?? undefined,
          datetime: t.datetime ? new Date(t.datetime) : undefined,
          authorizedDatetime: t.authorized_datetime ? new Date(t.authorized_datetime) : undefined,
          name: t.name,
          merchantName: t.merchant_name ?? undefined,
          merchantEntityId: t.merchant_entity_id ?? undefined,
          logoUrl: t.logo_url ?? undefined,
          website: t.website ?? undefined,
          paymentChannel: t.payment_channel,
          plaidCategoryLegacy: t.category ?? undefined,
          plaidCategoryIdLegacy: t.category_id ?? undefined,
          plaidPfcPrimary: pfc?.primary,
          plaidPfcDetailed: pfc?.detailed,
          plaidPfcConfidence: pfc?.confidence_level ?? undefined,
          categoryId: pfc?.detailed ? categoryMap.get(pfc.detailed) : undefined,
          locationCity: t.location?.city ?? undefined,
          locationRegion: t.location?.region ?? undefined,
          locationCountry: t.location?.country ?? undefined,
          locationPostalCode: t.location?.postal_code ?? undefined,
          locationLat: t.location?.lat?.toString(),
          locationLon: t.location?.lon?.toString(),
          removedAt: null,
          rawPayload: t,
          lastModifiedAt: new Date(),
        };
      })
      .filter((row): row is NonNullable<typeof row> => row !== null);

    if (rows.length === 0) {
      return;
    }

    const upserted = await tx
      .insert(transactions)
      .values(rows)
      .onConflictDoUpdate({
        target: transactions.plaidTransactionId,
        set: {
          pending: excludedValue(transactions.pending),
          pendingPlaidTransactionId: excludedValue(transactions.pendingPlaidTransactionId),
          amount: excludedValue(transactions.amount),
          isoCurrencyCode: excludedValue(transactions.isoCurrencyCode),
          unofficialCurrencyCode: excludedValue(transactions.unofficialCurrencyCode),
          date: excludedValue(transactions.date),
          authorizedDate: excludedValue(transactions.authorizedDate),
          datetime: excludedValue(transactions.datetime),
          authorizedDatetime: excludedValue(transactions.authorizedDatetime),
          name: excludedValue(transactions.name),
          merchantName: excludedValue(transactions.merchantName),
          merchantEntityId: excludedValue(transactions.merchantEntityId),
          logoUrl: excludedValue(transactions.logoUrl),
          website: excludedValue(transactions.website),
          paymentChannel: excludedValue(transactions.paymentChannel),
          plaidCategoryLegacy: excludedValue(transactions.plaidCategoryLegacy),
          plaidCategoryIdLegacy: excludedValue(transactions.plaidCategoryIdLegacy),
          plaidPfcPrimary: excludedValue(transactions.plaidPfcPrimary),
          plaidPfcDetailed: excludedValue(transactions.plaidPfcDetailed),
          plaidPfcConfidence: excludedValue(transactions.plaidPfcConfidence),
          categoryId: excludedValue(transactions.categoryId),
          locationCity: excludedValue(transactions.locationCity),
          locationRegion: excludedValue(transactions.locationRegion),
          locationCountry: excludedValue(transactions.locationCountry),
          locationPostalCode: excludedValue(transactions.locationPostalCode),
          locationLat: excludedValue(transactions.locationLat),
          locationLon: excludedValue(transactions.locationLon),
          removedAt: null,
          rawPayload: excludedValue(transactions.rawPayload),
          lastModifiedAt: excludedValue(transactions.lastModifiedAt),
          updatedAt: new Date(),
          // deliberately omitted: userCategoryId, userNotes, isHidden (user-owned fields)
        },
      })
      .returning({ id: transactions.id, plaidTransactionId: transactions.plaidTransactionId });

    const transactionDbIdByPlaidId = new Map(
      upserted.map((row) => [row.plaidTransactionId, row.id]),
    );
    await this.upsertCounterparties(tx, plaidTransactions, transactionDbIdByPlaidId);
  }

  /** Counterparties can change between syncs, so each touched transaction gets a clean delete-then-insert. */
  private async upsertCounterparties(
    tx: Database,
    plaidTransactions: Transaction[],
    transactionDbIdByPlaidId: Map<string, string>,
  ) {
    const withCounterparties = plaidTransactions.filter(
      (t) => t.counterparties && t.counterparties.length > 0,
    );
    if (withCounterparties.length === 0) {
      return;
    }

    const transactionDbIds = withCounterparties
      .map((t) => transactionDbIdByPlaidId.get(t.transaction_id))
      .filter((id): id is string => id !== undefined);
    if (transactionDbIds.length > 0) {
      await tx
        .delete(transactionCounterparties)
        .where(inArray(transactionCounterparties.transactionId, transactionDbIds));
    }

    const rows = withCounterparties.flatMap((t) => {
      const transactionId = transactionDbIdByPlaidId.get(t.transaction_id);
      if (!transactionId) return [];
      return (t.counterparties ?? []).map((cp) => ({
        transactionId,
        name: cp.name,
        type: cp.type,
        entityId: cp.entity_id ?? undefined,
        website: cp.website ?? undefined,
        logoUrl: cp.logo_url ?? undefined,
        confidenceLevel: cp.confidence_level ?? undefined,
      }));
    });

    if (rows.length > 0) {
      await tx.insert(transactionCounterparties).values(rows);
    }
  }

  private async softDeleteTransactions(tx: Database, removed: RemovedTransaction[]) {
    const ids = removed.map((r) => r.transaction_id);
    if (ids.length === 0) return;
    await tx
      .update(transactions)
      .set({ removedAt: new Date() })
      .where(inArray(transactions.plaidTransactionId, ids));
  }
}
