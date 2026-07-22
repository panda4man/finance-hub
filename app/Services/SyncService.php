<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Sync\UpsertTransactionsAction;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Exceptions\SimplefinException;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Services\Simplefin\SimplefinClient;
use App\Support\Simplefin\ProviderSyncPage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class SyncService
{
    public function __construct(
        private readonly ConnectionService $connections,
        private readonly SimplefinClient $client,
        private readonly CategorizationService $categorization,
        private readonly UpsertTransactionsAction $upsertTransactions,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function syncAllActiveConnections(SyncTrigger $trigger): array
    {
        $connectionIds = Connection::query()
            ->whereIn('status', [ConnectionStatus::Active, ConnectionStatus::PendingExpiration])
            ->pluck('id');

        $results = [];
        foreach ($connectionIds as $connectionId) {
            $results[] = $this->syncConnectionSafely($connectionId, $trigger);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    public function syncConnectionSafely(string $connectionId, SyncTrigger $trigger): array
    {
        try {
            return $this->syncConnection($connectionId, $trigger);
        } catch (\Throwable $e) {
            Log::error("Sync failed for connection {$connectionId}: {$e->getMessage()}");

            return ['connectionId' => $connectionId, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function syncConnection(string $connectionId, SyncTrigger $trigger): array
    {
        $connection = Connection::findOrFail($connectionId);

        $run = SyncRun::create([
            'connection_id' => $connectionId,
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
        ]);

        $connection->update(['last_attempted_sync_at' => now()]);

        $credential = $this->connections->decryptCredential($connectionId);
        $startDate = $connection->last_successful_sync_at
            ? $connection->last_successful_sync_at->clone()->subDays((int) config('finance.sync_overlap_days'))
            : null;

        $counters = ['pagesFetched' => 1, 'added' => 0, 'modified' => 0, 'removed' => 0, 'accountsUpserted' => 0];

        try {
            $page = $this->fetchWithRetry($credential, $startDate?->getTimestamp());

            DB::transaction(function () use ($connectionId, $page, &$counters, $connection, $run): void {
                $applied = $this->applyPage($connectionId, $page);
                $counters['accountsUpserted'] = $applied['accountsUpserted'];
                $counters['added'] = $applied['added'];
                $counters['modified'] = $applied['modified'];

                $connection->update([
                    'last_successful_sync_at' => now(),
                    'status' => ConnectionStatus::Active,
                    'status_detail' => null,
                ]);

                $run->update([
                    'status' => SyncStatus::Success,
                    'finished_at' => now(),
                    'pages_fetched' => $counters['pagesFetched'],
                    'added_count' => $counters['added'],
                    'modified_count' => $counters['modified'],
                    'removed_count' => $counters['removed'],
                    'accounts_upserted' => $counters['accountsUpserted'],
                ]);
            });

            Log::info(
                "Sync succeeded for connection {$connectionId} (run {$run->id}): ".
                "+{$counters['added']} added, {$counters['modified']} modified, {$counters['accountsUpserted']} account(s)"
            );

            return array_merge(['connectionId' => $connectionId, 'status' => 'success'], $counters);
        } catch (\Throwable $e) {
            $this->recordFailure($connectionId, $run->id, $e);
            throw $e;
        }
    }

    /**
     * Full-history backfill: walks backward from now in fixed-size windows,
     * upserting each non-empty window as it goes, until several consecutive
     * windows come back with no transactions at all — the signal that we've
     * walked past the start of whatever history the provider actually has.
     *
     * Unlike syncConnection(), this doesn't wrap the whole walk in one DB
     * transaction: each window commits independently so that if a later
     * (older) window fails, the history already pulled in earlier windows
     * stays committed instead of being rolled back.
     *
     * @return array<string, mixed>
     */
    public function backfillConnection(string $connectionId): array
    {
        $connection = Connection::findOrFail($connectionId);

        $run = SyncRun::create([
            'connection_id' => $connectionId,
            'trigger' => SyncTrigger::Backfill,
            'status' => SyncStatus::Running,
        ]);

        $connection->update(['last_attempted_sync_at' => now()]);

        $credential = $this->connections->decryptCredential($connectionId);
        $runStartedAt = CarbonImmutable::now();
        $windowDays = (int) config('finance.backfill_window_days');
        $emptyWindowsToStop = (int) config('finance.backfill_empty_windows_to_stop');
        $maxWindows = (int) config('finance.backfill_max_windows');

        $counters = ['pagesFetched' => 0, 'added' => 0, 'modified' => 0, 'removed' => 0, 'accountsUpserted' => 0];
        $consecutiveEmptyWindows = 0;
        $windowEnd = $runStartedAt;
        $reachedNaturalStop = false;

        try {
            for ($window = 0; $window < $maxWindows; $window++) {
                $windowStart = $windowEnd->subDays($windowDays);
                $page = $this->fetchWithRetry($credential, $windowStart->getTimestamp(), $windowEnd->getTimestamp());
                $counters['pagesFetched']++;

                $transactionCount = array_sum(array_map(
                    static fn ($account) => count($account->transactions),
                    $page->accounts
                ));

                if ($transactionCount === 0) {
                    $consecutiveEmptyWindows++;
                    if ($consecutiveEmptyWindows >= $emptyWindowsToStop) {
                        $reachedNaturalStop = true;
                        $windowEnd = $windowStart;
                        break;
                    }
                } else {
                    $consecutiveEmptyWindows = 0;
                    DB::transaction(function () use ($connectionId, $page, &$counters): void {
                        $applied = $this->applyPage($connectionId, $page);
                        $counters['accountsUpserted'] += $applied['accountsUpserted'];
                        $counters['added'] += $applied['added'];
                        $counters['modified'] += $applied['modified'];
                    });
                }

                $windowEnd = $windowStart;
            }

            if (! $reachedNaturalStop) {
                Log::warning(
                    "Backfill for connection {$connectionId} (run {$run->id}) hit the {$maxWindows}-window ".
                    "safety cap (back to {$windowEnd->toIso8601String()}) without a natural stop"
                );
            }

            if ($reachedNaturalStop && $connection->last_successful_sync_at === null) {
                $connection->update([
                    'last_successful_sync_at' => $runStartedAt,
                    'status' => ConnectionStatus::Active,
                    'status_detail' => null,
                ]);
            }

            $run->update([
                'status' => SyncStatus::Success,
                'finished_at' => now(),
                'pages_fetched' => $counters['pagesFetched'],
                'added_count' => $counters['added'],
                'modified_count' => $counters['modified'],
                'removed_count' => $counters['removed'],
                'accounts_upserted' => $counters['accountsUpserted'],
            ]);

            Log::info(
                "Backfill succeeded for connection {$connectionId} (run {$run->id}): ".
                "{$counters['pagesFetched']} window(s), +{$counters['added']} added, ".
                "{$counters['modified']} modified, {$counters['accountsUpserted']} account(s)"
            );

            return array_merge(['connectionId' => $connectionId, 'status' => 'success'], $counters);
        } catch (\Throwable $e) {
            $this->recordFailure($connectionId, $run->id, $e);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function backfillConnectionSafely(string $connectionId): array
    {
        try {
            return $this->backfillConnection($connectionId);
        } catch (\Throwable $e) {
            Log::error("Backfill failed for connection {$connectionId}: {$e->getMessage()}");

            return ['connectionId' => $connectionId, 'status' => 'failed', 'error' => $e->getMessage()];
        }
    }

    /**
     * Upserts one provider page (accounts/institutions + their transactions).
     * Caller is responsible for wrapping this in a DB transaction.
     *
     * @return array{accountsUpserted: int, added: int, modified: int}
     */
    private function applyPage(string $connectionId, ProviderSyncPage $page): array
    {
        $accountsUpserted = $this->connections->upsertAccountsAndInstitutions($connectionId, $page);
        $txnCounts = $this->upsertTransactions->execute($connectionId, $page);

        return [
            'accountsUpserted' => $accountsUpserted,
            'added' => $txnCounts['added'],
            'modified' => $txnCounts['modified'],
        ];
    }

    private function recordFailure(string $connectionId, string $runId, \Throwable $e): void
    {
        Log::error("Sync failed for connection {$connectionId} (run {$runId}): {$e->getMessage()}");

        if ($this->isAuthError($e)) {
            Log::warning("Connection {$connectionId} status -> login_required");

            Connection::whereKey($connectionId)->update([
                'status' => ConnectionStatus::LoginRequired,
                'status_detail' => $e->getMessage(),
            ]);
        }

        $errorCode = ($e instanceof SimplefinException && $e->errlistCodes !== [])
            ? $e->errlistCodes[0]
            : null;

        SyncRun::whereKey($runId)->update([
            'status' => SyncStatus::Failed,
            'finished_at' => now(),
            'error_code' => $errorCode,
            'error_message' => $e->getMessage(),
        ]);
    }

    private function fetchWithRetry(string $credential, ?int $startDate, ?int $endDate = null): ProviderSyncPage
    {
        $backoffsMs = config('finance.retry_backoff_ms');

        for ($attempt = 0; ; $attempt++) {
            try {
                $opts = [];
                if ($startDate !== null) {
                    $opts['startDate'] = $startDate;
                }
                if ($endDate !== null) {
                    $opts['endDate'] = $endDate;
                }

                return $this->client->fetchAccountSet($credential, $opts);
            } catch (\Throwable $e) {
                if ($attempt >= count($backoffsMs) || ! $this->isTransient($e)) {
                    throw $e;
                }

                Log::warning(
                    'Transient SimpleFin error on /accounts (attempt '.($attempt + 1).'), '.
                    "retrying in {$backoffsMs[$attempt]}ms: {$e->getMessage()}"
                );
                usleep($backoffsMs[$attempt] * 1000);
            }
        }
    }

    private function isAuthError(\Throwable $e): bool
    {
        if (! $e instanceof SimplefinException) {
            return false;
        }

        if ($e->status === 402 || $e->status === 403) {
            return true;
        }

        foreach ($e->errlistCodes as $code) {
            if (str_starts_with($code, 'gen.auth') || str_starts_with($code, 'con.auth')) {
                return true;
            }
        }

        return false;
    }

    private function isTransient(\Throwable $e): bool
    {
        if ($e instanceof SimplefinException) {
            if ($e->status !== null && $e->status >= 500) {
                return true;
            }

            foreach ($e->errlistCodes as $code) {
                if (str_starts_with($code, 'act.failed')) {
                    return true;
                }
            }

            return false;
        }

        // Non-SimplefinException transport errors (e.g. connection exceptions,
        // timeouts) are treated as transient — the request never got a
        // meaningful response to classify.
        return true;
    }

    /**
     * Latest sync_runs row per connection, newest first — "did last night's
     * sync work" at a glance.
     *
     * @return Collection<int, SyncRun>
     */
    public function getLatestRunsPerConnection(): Collection
    {
        return SyncRun::query()
            ->whereNotNull('connection_id')
            ->orderByDesc('started_at')
            ->get()
            ->unique('connection_id')
            ->values();
    }
}
