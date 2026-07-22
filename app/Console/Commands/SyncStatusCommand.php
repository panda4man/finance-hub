<?php

namespace App\Console\Commands;

use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncStatusCommand extends Command
{
    protected $signature = 'sync:status {--json : Output machine-readable JSON}';

    protected $description = 'Show the latest sync run per connection';

    public function handle(SyncService $syncService): int
    {
        $runs = $syncService->getLatestRunsPerConnection();
        $asJson = (bool) $this->option('json');

        if ($asJson) {
            $rows = $runs->map(fn ($run) => [
                'id' => $run->id,
                'connectionId' => $run->connection_id,
                'trigger' => $run->trigger->value,
                'status' => $run->status->value,
                'startedAt' => optional($run->started_at)->toIso8601String(),
                'finishedAt' => optional($run->finished_at)->toIso8601String(),
                'cursorBefore' => $run->cursor_before,
                'cursorAfter' => $run->cursor_after,
                'pagesFetched' => $run->pages_fetched,
                'addedCount' => $run->added_count,
                'modifiedCount' => $run->modified_count,
                'removedCount' => $run->removed_count,
                'accountsUpserted' => $run->accounts_upserted,
                'errorCode' => $run->error_code,
                'errorMessage' => $run->error_message,
                'createdAt' => optional($run->created_at)->toIso8601String(),
            ])->values()->all();

            $this->line(json_encode($rows));

            return self::SUCCESS;
        }

        if ($runs->isEmpty()) {
            $this->info('No sync runs recorded yet.');

            return self::SUCCESS;
        }

        foreach ($runs as $run) {
            $finishedAt = optional($run->finished_at)->toIso8601String() ?? 'in progress';

            $this->line(sprintf(
                '[%s] connection=%s trigger=%s status=%s started=%s finished=%s added=%d modified=%d removed=%d',
                $run->id,
                $run->connection_id,
                $run->trigger->value,
                $run->status->value,
                optional($run->started_at)->toIso8601String(),
                $finishedAt,
                $run->added_count,
                $run->modified_count,
                $run->removed_count,
            ));
        }

        return self::SUCCESS;
    }
}
