<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Jobs\BackfillConnectionJob;
use App\Models\Connection;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncBackfillCommand extends Command
{
    protected $signature = 'sync:backfill
        {--connection-id= : Backfill only this connection}
        {--sync : Run inline instead of dispatching to the queue}
        {--json : Output machine-readable JSON}';

    protected $description = 'Walk a connection (or all active connections) backward in time to backfill full transaction history';

    public function handle(SyncService $syncService): int
    {
        $connectionId = $this->option('connection-id');
        $inline = (bool) $this->option('sync');
        $asJson = (bool) $this->option('json');

        if ($connectionId !== null) {
            if ($inline) {
                $outcome = $syncService->backfillConnectionSafely($connectionId);

                return $this->renderOutcomes([$outcome], $asJson, single: true);
            }

            BackfillConnectionJob::dispatch($connectionId);

            return $this->renderDispatched([$connectionId], $asJson);
        }

        $connectionIds = Connection::query()
            ->whereIn('status', [ConnectionStatus::Active, ConnectionStatus::PendingExpiration])
            ->pluck('id')
            ->all();

        if ($inline) {
            $outcomes = [];
            foreach ($connectionIds as $id) {
                $outcomes[] = $syncService->backfillConnectionSafely($id);
            }

            return $this->renderOutcomes($outcomes, $asJson, single: false);
        }

        foreach ($connectionIds as $id) {
            BackfillConnectionJob::dispatch($id);
        }

        return $this->renderDispatched($connectionIds, $asJson);
    }

    /**
     * @param  list<array<string, mixed>>  $outcomes
     */
    private function renderOutcomes(array $outcomes, bool $asJson, bool $single): int
    {
        if ($asJson) {
            $this->line(json_encode($single ? ($outcomes[0] ?? null) : ['results' => $outcomes]));

            return self::SUCCESS;
        }

        foreach ($outcomes as $outcome) {
            if (($outcome['status'] ?? null) === 'success') {
                $this->info(sprintf(
                    '[%s] success — pages=%d added=%d modified=%d removed=%d accounts=%d',
                    $outcome['connectionId'],
                    $outcome['pagesFetched'] ?? 0,
                    $outcome['added'] ?? 0,
                    $outcome['modified'] ?? 0,
                    $outcome['removed'] ?? 0,
                    $outcome['accountsUpserted'] ?? 0,
                ));
            } else {
                $this->error(sprintf('[%s] failed — %s', $outcome['connectionId'], $outcome['error'] ?? 'unknown error'));
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $connectionIds
     */
    private function renderDispatched(array $connectionIds, bool $asJson): int
    {
        $queue = config('finance.sync_queue');

        if ($asJson) {
            $this->line(json_encode([
                'dispatched' => true,
                'queue' => $queue,
                'connectionIds' => $connectionIds,
            ]));

            return self::SUCCESS;
        }

        $this->info(sprintf('Dispatched sync for %d connection(s) to queue %s.', count($connectionIds), $queue));

        return self::SUCCESS;
    }
}
