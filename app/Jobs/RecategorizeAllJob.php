<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Categorization\RecategorizeAllAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class RecategorizeAllJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue(config('finance.sync_queue'));
    }

    public function handle(RecategorizeAllAction $action): void
    {
        $action->execute();
    }
}
