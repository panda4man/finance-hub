<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithTransactionFilters;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\TransactionQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find_repeat_purchases')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Description(<<<'MARKDOWN'
    Groups matching transactions by merchant and returns only merchants seen at
    least min_occurrences times, with totals and sample transactions.

    Example: "Find repeat purchases from Chase, checking or credit card only, in a
    date and amount range" -> call list_accounts{institution:"chase"} first, then
    find_repeat_purchases{institution:"chase", account_types:["checking","credit_card"],
    direction:"outflow", date_from, date_to, amount_min, amount_max}.
    MARKDOWN
)]
class FindRepeatPurchases extends Tool
{
    use InteractsWithTransactionFilters;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->resolveUser($request);

        $validated = $request->validate([
            ...$this->transactionFilterRules(),
            'min_occurrences' => ['nullable', 'integer', 'min:2', 'max:100'],
            'sort' => ['nullable', 'in:count,total,last_date'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            'sample_size' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $validated['direction'] ??= 'outflow';

        $filters = $this->transactionFiltersFrom($validated);
        $minOccurrences = $validated['min_occurrences'] ?? 2;
        $sort = $validated['sort'] ?? 'count';
        $limit = $validated['limit'] ?? 20;
        $sampleSize = $validated['sample_size'] ?? 3;

        $service = app(TransactionQueryService::class);
        $baseQuery = $service->applyFilters($service->ownedQuery($user), $filters);

        $grouped = (clone $baseQuery)->toBase()->select([])
            ->selectRaw(self::MERCHANT_KEY_SQL.' AS merchant_key')
            ->selectRaw('COUNT(*) AS txn_count')
            ->selectRaw('SUM(transactions.amount) AS total')
            ->selectRaw('AVG(transactions.amount) AS average')
            ->selectRaw('MIN(transactions.date) AS first_date')
            ->selectRaw('MAX(transactions.date) AS last_date')
            ->selectRaw('json_agg(DISTINCT transactions.account_id) AS account_ids')
            ->selectRaw('(array_agg(transactions.merchant_name ORDER BY transactions.date DESC, transactions.id DESC))[1] AS latest_merchant_name')
            ->selectRaw('(array_agg(transactions.name ORDER BY transactions.date DESC, transactions.id DESC))[1] AS latest_name')
            ->groupBy('merchant_key')
            ->havingRaw('COUNT(*) >= ?', [$minOccurrences]);

        match ($sort) {
            'total' => $grouped->orderByRaw('ABS(SUM(transactions.amount)) DESC'),
            'last_date' => $grouped->orderByDesc('last_date'),
            default => $grouped->orderByDesc('txn_count'),
        };

        $rows = $grouped->orderBy('merchant_key')->limit($limit + 1)->get();

        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit)->values();

        $accountIds = $rows->flatMap(fn ($row) => json_decode((string) $row->account_ids, true) ?? [])->unique()->values();
        $accounts = Account::query()->with('institution:id,name')->whereIn('id', $accountIds)->get()->keyBy('id');

        $samplesByKey = $sampleSize > 0
            ? $this->sampleTransactions($baseQuery, $rows->pluck('merchant_key')->all())
            : collect();

        return Response::structured([
            'applied_filters' => [
                ...$this->appliedFilters($filters),
                'min_occurrences' => $minOccurrences,
                'sort' => $sort,
                'limit' => $limit,
                'sample_size' => $sampleSize,
            ],
            'group_count' => $rows->count(),
            'truncated' => $truncated,
            'groups' => $rows->map(function ($row) use ($accounts, $samplesByKey, $sampleSize) {
                $rowAccountIds = json_decode((string) $row->account_ids, true) ?? [];

                return [
                    'merchant' => $row->merchant_key,
                    'display_name' => $row->latest_merchant_name ?: $row->latest_name,
                    'count' => (int) $row->txn_count,
                    'total' => $this->money($row->total),
                    'average' => $this->money($row->average),
                    'first_date' => $row->first_date,
                    'last_date' => $row->last_date,
                    'accounts' => collect($rowAccountIds)
                        ->map(fn ($id) => $accounts->get($id))
                        ->filter()
                        ->map(fn (Account $a) => [
                            'id' => $a->id,
                            'name' => $a->name,
                            'type' => $a->account_type?->value,
                            'institution' => $a->institution ? ['id' => $a->institution->id, 'name' => $a->institution->name] : null,
                        ])
                        ->values()
                        ->all(),
                    'sample_transactions' => $sampleSize > 0
                        ? ($samplesByKey->get($row->merchant_key, collect())->take($sampleSize)->map(fn ($t) => $this->presentTransaction($t))->values()->all())
                        : [],
                ];
            })->values()->all(),
        ]);
    }

    /**
     * @param  Builder<Transaction>  $baseQuery
     * @param  list<string>  $merchantKeys
     * @return Collection<string, Collection<int, Transaction>>
     */
    private function sampleTransactions(Builder $baseQuery, array $merchantKeys): Collection
    {
        if ($merchantKeys === []) {
            return collect();
        }

        $placeholders = implode(',', array_fill(0, count($merchantKeys), '?'));

        $transactions = (clone $baseQuery)
            ->addSelect([DB::raw(self::MERCHANT_KEY_SQL.' AS merchant_key')])
            ->whereRaw(self::MERCHANT_KEY_SQL.' IN ('.$placeholders.')', $merchantKeys)
            ->orderByDesc('transactions.date')
            ->orderByDesc('transactions.id')
            ->get();

        return $transactions->groupBy(fn (Transaction $t) => $t->merchant_key);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->transactionFilterSchema($schema),
            'min_occurrences' => $schema->integer()->min(2)->max(100)
                ->description('Only include merchants seen at least this many times. Default 2.'),
            'sort' => $schema->string()->enum(['count', 'total', 'last_date'])
                ->description('Sort groups by occurrence count, total amount magnitude, or most recent date. Default count.'),
            'limit' => $schema->integer()->min(1)->max(50)
                ->description('Max merchant groups to return (1-50). Default 20.'),
            'sample_size' => $schema->integer()->min(0)->max(10)
                ->description('Sample transactions to include per group, newest first (0-10). Default 3.'),
        ];
    }
}
