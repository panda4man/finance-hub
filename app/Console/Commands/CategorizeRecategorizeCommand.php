<?php

namespace App\Console\Commands;

use App\Actions\Categorization\RecategorizeAllAction;
use App\Jobs\RecategorizeAllJob;
use Illuminate\Console\Command;

class CategorizeRecategorizeCommand extends Command
{
    protected $signature = 'categorize:recategorize
        {--sync : Run inline instead of dispatching to the queue}
        {--json : Output machine-readable JSON}';

    protected $description = 'Recompute categories for every transaction using the current rule set';

    public function handle(RecategorizeAllAction $recategorizeAllAction): int
    {
        $asJson = (bool) $this->option('json');

        if ((bool) $this->option('sync')) {
            $result = $recategorizeAllAction->execute();

            if ($asJson) {
                $this->line(json_encode($result));

                return self::SUCCESS;
            }

            $this->info(sprintf('Recategorized %d of %d transaction(s).', $result['updated'], $result['scanned']));

            return self::SUCCESS;
        }

        RecategorizeAllJob::dispatch();
        $queue = config('finance.sync_queue');

        if ($asJson) {
            $this->line(json_encode(['dispatched' => true, 'queue' => $queue]));

            return self::SUCCESS;
        }

        $this->info(sprintf('Dispatched recategorization to queue %s.', $queue));

        return self::SUCCESS;
    }
}
