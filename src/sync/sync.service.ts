import { Inject, Injectable, Logger } from '@nestjs/common';
import { eq, inArray, desc, sql, type AnyColumn } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { connections, accounts, transactions, syncRuns, type SyncTrigger } from '../db/schema';
import { ConnectionsService } from '../connections/connections.service';
import { SimplefinService } from '../simplefin/simplefin.service';
import { CategorizationService } from '../categorization/categorization.service';
import { isAuthError, isTransientSimplefinError, SimplefinApiError } from '../simplefin/simplefin-error';
import type { ProviderSyncPage } from '../simplefin/simplefin.types';

const RETRY_DELAYS_MS = [1000, 4000, 10000];
/** Overlap window re-requested each sync to catch pending -> posted transitions (no cursor in the protocol). */
const SYNC_OVERLAP_DAYS = 4;

/** Size of each backward-walking window during a full-history backfill. */
const BACKFILL_WINDOW_DAYS = 90;
/**
 * Consecutive empty windows required before concluding we've walked past the
 * start of the provider's history. A single empty window isn't enough — a
 * dormant account can have a multi-month gap with real history still further
 * back, so we keep walking a few more windows before calling it.
 */
const BACKFILL_EMPTY_WINDOWS_TO_STOP = 3;
/** Safety cap (~50 years) in case a provider never actually returns an empty window. */
const BACKFILL_MAX_WINDOWS = 200;

type SyncOutcome =
  | {
      connectionId: string;
      status: 'success';
      pagesFetched: number;
      added: number;
      modified: number;
      removed: number;
      accountsUpserted: number;
    }
  | { connectionId: string; status: 'failed'; error: string };

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
    private readonly connectionsService: ConnectionsService,
    private readonly simplefin: SimplefinService,
    private readonly categorization: CategorizationService,
  ) {}

  async syncAllActiveConnections(trigger: SyncTrigger): Promise<SyncOutcome[]> {
    const activeConnections = await this.db
      .select({ id: connections.id })
      .from(connections)
      .where(inArray(connections.status, ['active', 'pending_expiration']));

    const results: SyncOutcome[] = [];
    for (const connection of activeConnections) {
      results.push(await this.syncConnectionSafely(connection.id, trigger));
    }
    return results;
  }

  async syncConnectionSafely(connectionId: string, trigger: SyncTrigger): Promise<SyncOutcome> {
    try {
      return await this.syncConnection(connectionId, trigger);
    } catch (err) {
      const message = (err as Error).message;
      this.logger.error(`Sync failed for connection ${connectionId}: ${message}`);
      return { connectionId, status: 'failed', error: message };
    }
  }

  async syncConnection(connectionId: string, trigger: SyncTrigger): Promise<SyncOutcome> {
    const [connection] = await this.db
      .select()
      .from(connections)
      .where(eq(connections.id, connectionId))
      .limit(1);
    if (!connection) {
      throw new Error(`Connection ${connectionId} not found`);
    }

    const [{ id: runId }] = await this.db
      .insert(syncRuns)
      .values({ connectionId, trigger, status: 'running' })
      .returning({ id: syncRuns.id });

    this.logger.log(`Starting ${trigger} sync for connection ${connectionId} (run ${runId})`);

    await this.db
      .update(connections)
      .set({ lastAttemptedSyncAt: new Date() })
      .where(eq(connections.id, connectionId));

    const credential = await this.connectionsService.decryptCredential(connectionId);
    const startDate = connection.lastSuccessfulSyncAt
      ? new Date(connection.lastSuccessfulSyncAt.getTime() - SYNC_OVERLAP_DAYS * 24 * 60 * 60 * 1000)
      : undefined;

    const counters = { pagesFetched: 1, added: 0, modified: 0, removed: 0, accountsUpserted: 0 };

    try {
      const page = await this.fetchWithRetry(credential, startDate);

      await this.db.transaction(async (tx) => {
        const applied = await this.applyPage(tx, connectionId, page);
        counters.accountsUpserted = applied.accountsUpserted;
        counters.added = applied.added;
        counters.modified = applied.modified;

        await tx
          .update(connections)
          .set({ lastSuccessfulSyncAt: new Date(), status: 'active', statusDetail: null })
          .where(eq(connections.id, connectionId));

        await tx
          .update(syncRuns)
          .set({
            status: 'success',
            finishedAt: new Date(),
            pagesFetched: counters.pagesFetched,
            addedCount: counters.added,
            modifiedCount: counters.modified,
            removedCount: counters.removed,
            accountsUpserted: counters.accountsUpserted,
          })
          .where(eq(syncRuns.id, runId));
      });

      this.logger.log(
        `Sync succeeded for connection ${connectionId} (run ${runId}): ` +
          `+${counters.added} added, ${counters.modified} modified, ${counters.accountsUpserted} account(s)`,
      );
      return { connectionId, status: 'success', ...counters };
    } catch (err) {
      await this.recordFailure(connectionId, runId, err);
      throw err;
    }
  }

  /**
   * Full-history backfill: walks backward from now in fixed-size windows,
   * upserting each non-empty window as it goes, until several consecutive
   * windows come back with no transactions at all — the signal that we've
   * walked past the start of whatever history the provider actually has.
   *
   * Unlike `syncConnection`, this doesn't wrap the whole walk in one DB
   * transaction: each window commits independently so that if a later
   * (older) window fails, the history already pulled in earlier windows
   * stays committed instead of being rolled back.
   */
  async backfillConnectionSafely(connectionId: string): Promise<SyncOutcome> {
    try {
      return await this.backfillConnection(connectionId);
    } catch (err) {
      const message = (err as Error).message;
      this.logger.error(`Backfill failed for connection ${connectionId}: ${message}`);
      return { connectionId, status: 'failed', error: message };
    }
  }

  async backfillConnection(connectionId: string): Promise<SyncOutcome> {
    const [connection] = await this.db
      .select()
      .from(connections)
      .where(eq(connections.id, connectionId))
      .limit(1);
    if (!connection) {
      throw new Error(`Connection ${connectionId} not found`);
    }

    const [{ id: runId }] = await this.db
      .insert(syncRuns)
      .values({ connectionId, trigger: 'backfill', status: 'running' })
      .returning({ id: syncRuns.id });

    this.logger.log(`Starting full-history backfill for connection ${connectionId} (run ${runId})`);

    await this.db
      .update(connections)
      .set({ lastAttemptedSyncAt: new Date() })
      .where(eq(connections.id, connectionId));

    const credential = await this.connectionsService.decryptCredential(connectionId);
    const runStartedAt = new Date();
    const counters = { pagesFetched: 0, added: 0, modified: 0, removed: 0, accountsUpserted: 0 };
    let consecutiveEmptyWindows = 0;
    let windowEnd = runStartedAt;
    let reachedNaturalStop = false;

    try {
      for (let window = 0; window < BACKFILL_MAX_WINDOWS; window += 1) {
        const windowStart = new Date(windowEnd.getTime() - BACKFILL_WINDOW_DAYS * 24 * 60 * 60 * 1000);
        const page = await this.fetchWithRetry(credential, windowStart, windowEnd);
        counters.pagesFetched += 1;

        const transactionCount = page.accounts.reduce((sum, a) => sum + a.transactions.length, 0);
        if (transactionCount === 0) {
          consecutiveEmptyWindows += 1;
          if (consecutiveEmptyWindows >= BACKFILL_EMPTY_WINDOWS_TO_STOP) {
            reachedNaturalStop = true;
            windowEnd = windowStart;
            break;
          }
        } else {
          consecutiveEmptyWindows = 0;
          await this.db.transaction(async (tx) => {
            const applied = await this.applyPage(tx, connectionId, page);
            counters.accountsUpserted += applied.accountsUpserted;
            counters.added += applied.added;
            counters.modified += applied.modified;
          });
        }

        windowEnd = windowStart;
      }

      if (!reachedNaturalStop) {
        this.logger.warn(
          `Backfill for connection ${connectionId} (run ${runId}) hit the ${BACKFILL_MAX_WINDOWS}-window ` +
            `safety cap (back to ${windowEnd.toISOString()}) without a natural stop`,
        );
      }

      if (reachedNaturalStop && !connection.lastSuccessfulSyncAt) {
        await this.db
          .update(connections)
          .set({ lastSuccessfulSyncAt: runStartedAt, status: 'active', statusDetail: null })
          .where(eq(connections.id, connectionId));
      }

      await this.db
        .update(syncRuns)
        .set({
          status: 'success',
          finishedAt: new Date(),
          pagesFetched: counters.pagesFetched,
          addedCount: counters.added,
          modifiedCount: counters.modified,
          removedCount: counters.removed,
          accountsUpserted: counters.accountsUpserted,
        })
        .where(eq(syncRuns.id, runId));

      this.logger.log(
        `Backfill succeeded for connection ${connectionId} (run ${runId}): ` +
          `${counters.pagesFetched} window(s), +${counters.added} added, ${counters.modified} modified, ` +
          `${counters.accountsUpserted} account(s)`,
      );
      return { connectionId, status: 'success', ...counters };
    } catch (err) {
      await this.recordFailure(connectionId, runId, err);
      throw err;
    }
  }

  /** Upserts one provider page (accounts/institutions + their transactions) within an existing transaction. */
  private async applyPage(
    tx: Database,
    connectionId: string,
    page: ProviderSyncPage,
  ): Promise<{ accountsUpserted: number; added: number; modified: number }> {
    const accountsUpserted = await this.connectionsService.upsertAccountsAndInstitutions(tx, connectionId, page);

    const accountIdMap = await this.loadAccountMap(tx, connectionId);
    const allTransactions = page.accounts.flatMap((a) =>
      a.transactions.map((t) => ({ ...t, externalAccountId: a.externalAccountId })),
    );

    let added = 0;
    let modified = 0;
    if (allTransactions.length > 0) {
      const existingIds = await this.loadExistingTransactionIds(
        tx,
        allTransactions.map((t) => t.externalTransactionId),
      );
      modified = allTransactions.filter((t) => existingIds.has(t.externalTransactionId)).length;
      added = allTransactions.length - modified;

      await this.upsertTransactions(tx, connectionId, allTransactions, accountIdMap);
    }

    return { accountsUpserted, added, modified };
  }

  private async recordFailure(connectionId: string, runId: string, err: unknown) {
    const errorCode = err instanceof SimplefinApiError ? err.code : undefined;
    const errorMessage = (err as Error).message;

    this.logger.error(`Sync failed for connection ${connectionId} (run ${runId}): ${errorMessage}`);

    if (isAuthError(err)) {
      this.logger.warn(`Connection ${connectionId} status -> login_required`);
      await this.db
        .update(connections)
        .set({ status: 'login_required', statusDetail: errorMessage })
        .where(eq(connections.id, connectionId));
    }

    await this.db
      .update(syncRuns)
      .set({ status: 'failed', finishedAt: new Date(), errorCode, errorMessage })
      .where(eq(syncRuns.id, runId));
  }

  private async fetchWithRetry(
    credential: string,
    startDate: Date | undefined,
    endDate?: Date,
  ): Promise<ProviderSyncPage> {
    for (let attempt = 0; ; attempt += 1) {
      try {
        return await this.simplefin.fetchAccountSet(credential, { startDate, endDate });
      } catch (err) {
        if (attempt >= RETRY_DELAYS_MS.length || !isTransientSimplefinError(err)) {
          throw err;
        }
        this.logger.warn(
          `Transient SimpleFin error on /accounts (attempt ${attempt + 1}), retrying in ${RETRY_DELAYS_MS[attempt]}ms`,
        );
        await sleep(RETRY_DELAYS_MS[attempt]);
      }
    }
  }

  /** Latest sync_runs row per connection, newest first — "did last night's sync work" at a glance. */
  async getLatestRunsPerConnection() {
    const rows = await this.db.select().from(syncRuns).orderBy(desc(syncRuns.startedAt));

    const latestByConnection = new Map<string, (typeof rows)[number]>();
    for (const row of rows) {
      if (row.connectionId && !latestByConnection.has(row.connectionId)) {
        latestByConnection.set(row.connectionId, row);
      }
    }
    return [...latestByConnection.values()];
  }

  private async loadAccountMap(tx: Database, connectionId: string): Promise<Map<string, string>> {
    const rows = await tx
      .select({ id: accounts.id, externalAccountId: accounts.externalAccountId })
      .from(accounts)
      .where(eq(accounts.connectionId, connectionId));
    return new Map(rows.map((row) => [row.externalAccountId, row.id]));
  }

  private async loadExistingTransactionIds(tx: Database, externalIds: string[]): Promise<Set<string>> {
    if (externalIds.length === 0) {
      return new Set();
    }
    const rows = await tx
      .select({ externalTransactionId: transactions.externalTransactionId })
      .from(transactions)
      .where(inArray(transactions.externalTransactionId, externalIds));
    return new Set(rows.map((r) => r.externalTransactionId));
  }

  private async upsertTransactions(
    tx: Database,
    connectionId: string,
    providerTransactions: (ProviderSyncPage['accounts'][number]['transactions'][number] & {
      externalAccountId: string;
    })[],
    accountIdMap: Map<string, string>,
  ) {
    const rows = providerTransactions
      .map((t) => {
        const accountId = accountIdMap.get(t.externalAccountId);
        if (!accountId) {
          this.logger.warn(`Skipping transaction ${t.externalTransactionId}: unknown account ${t.externalAccountId}`);
          return null;
        }
        return {
          accountId,
          connectionId,
          externalTransactionId: t.externalTransactionId,
          pending: t.pending,
          amount: t.amount,
          date: t.date,
          datetime: t.datetime,
          name: t.name,
          categoryId: this.categorization.categorize({ name: t.name, amount: t.amount }),
          removedAt: null,
          rawPayload: t.raw,
          lastModifiedAt: new Date(),
        };
      })
      .filter((row): row is NonNullable<typeof row> => row !== null);

    if (rows.length === 0) {
      return;
    }

    await tx
      .insert(transactions)
      .values(rows)
      .onConflictDoUpdate({
        target: transactions.externalTransactionId,
        set: {
          pending: excludedValue(transactions.pending),
          amount: excludedValue(transactions.amount),
          date: excludedValue(transactions.date),
          datetime: excludedValue(transactions.datetime),
          name: excludedValue(transactions.name),
          categoryId: excludedValue(transactions.categoryId),
          removedAt: null,
          rawPayload: excludedValue(transactions.rawPayload),
          lastModifiedAt: excludedValue(transactions.lastModifiedAt),
          updatedAt: new Date(),
          // categoryId is system/engine-owned and is recomputed on every sync so rule
          // improvements retroactively re-apply. userCategoryId/userNotes/isHidden are
          // genuinely user-owned and must never be clobbered by a sync.
          // deliberately omitted: userCategoryId, userNotes, isHidden
        },
      });
  }
}
