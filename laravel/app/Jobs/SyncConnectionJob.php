<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SyncTrigger;
use App\Services\SyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class SyncConnectionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry logic lives inside SyncService (see config/finance.php's
     * retry_backoff_ms), not the queue — a job failure here is final.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly string $connectionId,
        public readonly SyncTrigger $trigger,
    ) {
        $this->onQueue(config('finance.sync_queue'));
    }

    public function handle(SyncService $syncService): void
    {
        $syncService->syncConnectionSafely($this->connectionId, $this->trigger);
    }
}
