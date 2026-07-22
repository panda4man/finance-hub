<?php

namespace App\Console\Commands;

use App\Enums\ConnectionStatus;
use App\Models\Connection;
use Illuminate\Console\Command;

class SyncCatchUpIfStaleCommand extends Command
{
    protected $signature = 'sync:catch-up-if-stale';

    protected $description = 'Run a catch-up sync if any active connection has never synced or is past the stale threshold';

    public function handle(): int
    {
        $thresholdHours = (int) config('finance.stale_sync_threshold_hours');

        $active = Connection::query()
            ->whereIn('status', [ConnectionStatus::Active, ConnectionStatus::PendingExpiration])
            ->get(['id', 'last_successful_sync_at']);

        $isStale = $active->contains(
            fn ($connection) => $connection->last_successful_sync_at === null
                || $connection->last_successful_sync_at->diffInHours(now()) > $thresholdHours
        );

        if ($active->isNotEmpty() && $isStale) {
            $this->call('sync:run', ['--trigger' => 'scheduled']);
            $this->info('Stale sync detected; dispatched catch-up sync.');

            return self::SUCCESS;
        }

        $this->info('Sync is current; nothing to do.');

        return self::SUCCESS;
    }
}
