<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncConnectionJob;
use App\Models\Connection;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncRunCommand extends Command
{
    protected $signature = 'sync:run
        {--connection-id= : Sync only this connection}
        {--sync : Run inline instead of dispatching to the queue}
        {--trigger=manual : Sync trigger recorded on the sync_runs row}
        {--json : Output machine-readable JSON}';

    protected $description = 'Run a SimpleFin sync for one connection or all active connections';

    public function handle(SyncService $syncService): int
    {
        $trigger = SyncTrigger::from($this->option('trigger'));
        $connectionId = $this->option('connection-id');
        $inline = (bool) $this->option('sync');
        $asJson = (bool) $this->option('json');

        if ($connectionId !== null) {
            if ($inline) {
                $outcome = $syncService->syncConnectionSafely($connectionId, $trigger);

                return $this->renderOutcomes([$outcome], $asJson, single: true);
            }

            SyncConnectionJob::dispatch($connectionId, $trigger);

            return $this->renderDispatched([$connectionId], $asJson);
        }

        if ($inline) {
            $outcomes = $syncService->syncAllActiveConnections($trigger);

            return $this->renderOutcomes($outcomes, $asJson, single: false);
        }

        $connectionIds = Connection::query()
            ->whereIn('status', [ConnectionStatus::Active, ConnectionStatus::PendingExpiration])
            ->pluck('id')
            ->all();

        foreach ($connectionIds as $id) {
            SyncConnectionJob::dispatch($id, $trigger);
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
