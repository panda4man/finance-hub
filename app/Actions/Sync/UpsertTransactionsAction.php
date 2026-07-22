<?php

declare(strict_types=1);

namespace App\Actions\Sync;

use App\Models\Account;
use App\Models\Transaction;
use App\Services\CategorizationService;
use App\Support\Simplefin\ProviderSyncPage;
use App\Support\Simplefin\ProviderTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Upserts one provider page's transactions into `transactions`. Accounts and
 * institutions are handled separately by
 * ConnectionService::upsertAccountsAndInstitutions() — call that first so the
 * account map below is complete.
 *
 * Uses a raw DB::table() upsert (not Eloquent) so the ON CONFLICT clause can
 * reference `excluded.*` per-column and so category_id is recomputed on every
 * upsert (including re-syncs) while user-owned columns are never touched.
 * Does NOT open its own transaction — the caller (SyncService) wraps this
 * together with the accounts/institutions upsert and the connection/sync-run
 * bookkeeping in one atomic unit.
 */
final class UpsertTransactionsAction
{
    public function __construct(private readonly CategorizationService $categorization) {}

    /**
     * @return array{added: int, modified: int}
     */
    public function execute(string $connectionId, ProviderSyncPage $page): array
    {
        /** @var Collection<string, string> $accountIdMap external_account_id => id */
        $accountIdMap = Account::query()
            ->where('connection_id', $connectionId)
            ->pluck('id', 'external_account_id');

        /** @var list<array{0: ProviderTransaction, 1: string}> $pairs */
        $pairs = [];
        foreach ($page->accounts as $account) {
            $accountId = $accountIdMap->get($account->externalAccountId);

            if ($accountId === null) {
                Log::warning(
                    "Skipping transactions for connection {$connectionId}: ".
                    "unknown account {$account->externalAccountId}"
                );

                continue;
            }

            foreach ($account->transactions as $transaction) {
                $pairs[] = [$transaction, $accountId];
            }
        }

        if ($pairs === []) {
            return ['added' => 0, 'modified' => 0];
        }

        $externalIds = array_map(static fn (array $pair) => $pair[0]->externalTransactionId, $pairs);

        $existingIds = array_flip(
            Transaction::query()
                ->whereIn('external_transaction_id', $externalIds)
                ->pluck('external_transaction_id')
                ->all()
        );

        $added = 0;
        $modified = 0;
        $rows = [];

        foreach ($pairs as [$transaction, $accountId]) {
            if (isset($existingIds[$transaction->externalTransactionId])) {
                $modified++;
            } else {
                $added++;
            }

            $categoryId = $this->categorization->categorize([
                'name' => $transaction->name,
                'amount' => $transaction->amount,
            ]);

            $rows[] = [
                'id' => (string) Str::uuid(),
                'account_id' => $accountId,
                'connection_id' => $connectionId,
                'external_transaction_id' => $transaction->externalTransactionId,
                'pending' => $transaction->pending,
                'amount' => $transaction->amount,
                'date' => $transaction->date->format('Y-m-d'),
                'datetime' => $transaction->datetime?->format('Y-m-d H:i:sP'),
                'name' => $transaction->name,
                'category_id' => $categoryId,
                'raw_payload' => json_encode($transaction->rawPayload),
            ];
        }

        DB::table('transactions')->upsert(
            $rows,
            ['external_transaction_id'],
            [
                // Copy the incoming (excluded) value for provider-controlled columns.
                'account_id' => DB::raw('excluded.account_id'),
                'connection_id' => DB::raw('excluded.connection_id'),
                'pending' => DB::raw('excluded.pending'),
                'amount' => DB::raw('excluded.amount'),
                'date' => DB::raw('excluded.date'),
                'datetime' => DB::raw('excluded.datetime'),
                'name' => DB::raw('excluded.name'),
                // Recomputed on every upsert (not just new rows) so category-rule
                // improvements retroactively re-apply on the next sync.
                'category_id' => DB::raw('excluded.category_id'),
                'raw_payload' => DB::raw('excluded.raw_payload'),
                // A transaction reappearing in a provider page is, by definition,
                // no longer removed.
                'removed_at' => DB::raw('null'),
                'last_modified_at' => DB::raw('now()'),
                'updated_at' => DB::raw('now()'),
                // Deliberately excluded: user_category_id, user_notes, is_hidden
                // (user-owned, must survive a sync untouched), first_seen_at,
                // created_at (immutable), pending_external_transaction_id (not
                // sourced from ProviderTransaction).
            ]
        );

        return ['added' => $added, 'modified' => $modified];
    }
}
