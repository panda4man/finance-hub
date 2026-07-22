<?php

declare(strict_types=1);

namespace App\Actions\Import;

use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Support\Import\ParsedImportRow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Upserts parsed CSV rows into `transactions`, mirroring
 * App\Actions\Sync\UpsertTransactionsAction: a raw DB::table() upsert on
 * `external_transaction_id` whose update clause covers only import-owned
 * columns, so re-imports never clobber user_category_id/user_notes/is_hidden
 * or the immutable first_seen_at/created_at.
 */
final class UpsertImportedTransactionsAction
{
    public function __construct(private readonly CategorizationService $categorization) {}

    /**
     * @param  list<ParsedImportRow>  $rows
     * @return array{added: int, duplicate: int}
     */
    public function execute(string $accountId, string $connectionId, array $rows): array
    {
        if ($rows === []) {
            return ['added' => 0, 'duplicate' => 0];
        }

        $externalIds = array_map(static fn (ParsedImportRow $row) => $row->externalTransactionId, $rows);

        $existingIds = array_flip(
            Transaction::query()
                ->whereIn('external_transaction_id', $externalIds)
                ->pluck('external_transaction_id')
                ->all()
        );

        $added = 0;
        $duplicate = 0;
        $dbRows = [];

        foreach ($rows as $row) {
            if (isset($existingIds[$row->externalTransactionId])) {
                $duplicate++;
            } else {
                $added++;
            }

            $categoryId = $this->categorization->categorize([
                'name' => $row->description,
                'amount' => $row->amount,
            ]);

            $dbRows[] = [
                'id' => (string) Str::uuid(),
                'account_id' => $accountId,
                'connection_id' => $connectionId,
                'external_transaction_id' => $row->externalTransactionId,
                'pending' => false,
                'amount' => $row->amount,
                'date' => $row->postingDate,
                'name' => $row->description,
                'category_id' => $categoryId,
                'raw_payload' => json_encode($row->rawRow),
            ];
        }

        DB::table('transactions')->upsert(
            $dbRows,
            ['external_transaction_id'],
            [
                'account_id' => DB::raw('excluded.account_id'),
                'connection_id' => DB::raw('excluded.connection_id'),
                'pending' => DB::raw('excluded.pending'),
                'amount' => DB::raw('excluded.amount'),
                'date' => DB::raw('excluded.date'),
                'name' => DB::raw('excluded.name'),
                // Recomputed on every upsert so category-rule improvements
                // retroactively re-apply on the next import, same as sync.
                'category_id' => DB::raw('excluded.category_id'),
                'raw_payload' => DB::raw('excluded.raw_payload'),
                'removed_at' => DB::raw('null'),
                'last_modified_at' => DB::raw('now()'),
                'updated_at' => DB::raw('now()'),
                // Deliberately excluded: user_category_id, user_notes,
                // is_hidden (user-owned), first_seen_at, created_at
                // (immutable).
            ]
        );

        return ['added' => $added, 'duplicate' => $duplicate];
    }
}
