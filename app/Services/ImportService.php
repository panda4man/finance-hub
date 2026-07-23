<?php

declare(strict_types=1);

namespace App\Services;

use App\Actions\Import\UpsertImportedTransactionsAction;
use App\Enums\ConnectionStatus;
use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ImportRun;
use App\Models\ImportTemplate;
use App\Support\Import\DedupeKeyValidator;
use App\Support\Import\GenericCsvParser;
use App\Support\Import\ParsedImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ImportService
{
    public function __construct(
        private readonly GenericCsvParser $parser,
        private readonly UpsertImportedTransactionsAction $upsertTransactions,
    ) {}

    /**
     * One shared 'manual' Connection per user holds every CSV-imported
     * account — there's no external credential to store, just a home for
     * accounts.connection_id (which is required, non-nullable).
     */
    public function ensureManualConnection(string $userId): Connection
    {
        return Connection::query()->firstOrCreate(
            ['user_id' => $userId, 'provider' => 'manual'],
            ['status' => ConnectionStatus::Active],
        );
    }

    public function createManualAccount(string $userId, string $name, ?string $mask, ?string $type, ?string $institutionId = null): Account
    {
        $connection = $this->ensureManualConnection($userId);

        return Account::create([
            'connection_id' => $connection->id,
            'institution_id' => $institutionId,
            // accounts.external_account_id is globally unique NOT NULL; CSV
            // accounts have no provider-issued id, so synthesize one.
            'external_account_id' => 'manual:'.Str::uuid(),
            'name' => $name,
            'mask' => $mask,
            'type' => $type,
        ]);
    }

    public function importFile(string $accountId, string $templateId, string $filePath, string $fileName): ImportRun
    {
        $account = Account::findOrFail($accountId);
        $template = ImportTemplate::findOrFail($templateId);

        // Reject before any ImportRun row exists — an invalid dedupe config
        // isn't a per-file failure, it's a template that can never safely
        // import anything, so no orphaned Running/Failed run should be left
        // behind for it.
        DedupeKeyValidator::assertMapped($template);

        $run = ImportRun::create([
            'connection_id' => $account->connection_id,
            'account_id' => $accountId,
            'status' => ImportStatus::Running,
            'file_name' => $fileName,
            'file_path' => $filePath,
        ]);

        try {
            ['rows' => $rows, 'failures' => $failures] = $this->parser->parse($template, $accountId, $filePath);

            $counts = ['added' => 0, 'duplicate' => 0];

            DB::transaction(function () use ($accountId, $account, $rows, &$counts): void {
                $counts = $this->upsertTransactions->execute($accountId, $account->connection_id, $rows);
                $this->updateAccountBalance($account, $rows);
            });

            $failedCount = count($failures);
            $status = match (true) {
                $failedCount === 0 => ImportStatus::Success,
                ($counts['added'] + $counts['duplicate']) > 0 => ImportStatus::Partial,
                default => ImportStatus::Failed,
            };

            $run->update([
                'status' => $status,
                'finished_at' => now(),
                'row_count' => count($rows) + $failedCount,
                'added_count' => $counts['added'],
                'duplicate_count' => $counts['duplicate'],
                'failed_count' => $failedCount,
                'error_message' => $failures === [] ? null : implode("\n", array_slice($failures, 0, 20)),
            ]);

            Log::info(
                "Import succeeded for account {$accountId} (run {$run->id}): ".
                "+{$counts['added']} added, {$counts['duplicate']} duplicate, {$failedCount} failed"
            );

            return $run->refresh();
        } catch (\Throwable $e) {
            $this->recordFailure($run->id, $e);
            throw $e;
        }
    }

    /**
     * Sets the account's current_balance from the newest dated row that has
     * a non-blank Balance (Chase leaves the top/newest row's Balance blank
     * in some exports). Guarded so importing an older or overlapping export
     * never regresses a balance already advanced by a newer import.
     *
     * @param  list<ParsedImportRow>  $rows
     */
    private function updateAccountBalance(Account $account, array $rows): void
    {
        // Chase lists rows newest-first, including within a single date, so
        // among same-date rows the first one encountered in file order is
        // the chronologically latest — only a strictly later date should
        // overwrite $latest, otherwise a same-day tie would keep clobbering
        // it down to the day's earliest (least current) balance.
        $latest = null;
        foreach ($rows as $row) {
            if ($row->balance === null) {
                continue;
            }
            if ($latest === null || $row->postingDate > $latest->postingDate) {
                $latest = $row;
            }
        }

        if ($latest === null) {
            return;
        }

        if ($account->balances_updated_at !== null
            && $latest->postingDate < $account->balances_updated_at->toDateString()) {
            return;
        }

        $account->update([
            'current_balance' => $latest->balance,
            'balances_updated_at' => $latest->postingDate,
        ]);
    }

    private function recordFailure(string $runId, \Throwable $e): void
    {
        Log::error("Import failed (run {$runId}): {$e->getMessage()}");

        ImportRun::whereKey($runId)->update([
            'status' => ImportStatus::Failed,
            'finished_at' => now(),
            'error_message' => $e->getMessage(),
        ]);
    }
}
