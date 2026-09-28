<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithTransactionFilters;
use App\Services\TransactionQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_merchants')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Description(<<<'MARKDOWN'
    Finds the merchant identities behind a human search term (e.g. "Duke Energy",
    "Target") by matching every word in query against the transaction's merchant
    name or raw description. Call this before search_transactions,
    find_repeat_purchases or spending_trend, and pass a result's "merchant" value
    as the merchant argument to those tools.
    MARKDOWN
)]
class SearchMerchants extends Tool
{
    use InteractsWithTransactionFilters;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->resolveUser($request);

        $rules = [
            'query' => ['required', 'string', 'min:2', 'max:100'],
            ...$this->transactionFilterRules(except: ['merchant']),
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];

        $validated = $request->validate($rules);

        $tokens = collect(preg_split('/[^\pL\pN]+/u', mb_strtolower($validated['query']), -1, PREG_SPLIT_NO_EMPTY))
            ->filter(fn (string $t) => mb_strlen($t) >= 2)
            ->values();

        if ($tokens->isEmpty()) {
            throw ValidationException::withMessages([
                'query' => 'The query must contain at least one word of 2+ characters.',
            ]);
        }

        $filters = $this->transactionFiltersFrom($validated);
        $limit = $validated['limit'] ?? 20;

        $service = app(TransactionQueryService::class);
        $query = $service->applyFilters($service->ownedQuery($user), $filters);

        foreach ($tokens as $token) {
            $pattern = '%'.$service->escapeLike($token).'%';
            $query->where(
                fn ($m) => $m->whereLike('transactions.merchant_name', $pattern)
                    ->orWhereLike('transactions.name', $pattern),
            );
        }

        $rows = $query->toBase()->select([])
            ->selectRaw(self::MERCHANT_KEY_SQL.' AS merchant_key')
            ->selectRaw('COUNT(*) AS txn_count')
            ->selectRaw('SUM(transactions.amount) AS total')
            ->selectRaw('MIN(transactions.date) AS first_date')
            ->selectRaw('MAX(transactions.date) AS last_date')
            ->selectRaw('json_agg(DISTINCT transactions.name) AS names')
            ->selectRaw("json_agg(DISTINCT transactions.merchant_name) FILTER (WHERE transactions.merchant_name IS NOT NULL AND transactions.merchant_name != '') AS merchant_names")
            ->groupBy('merchant_key')
            ->orderByDesc('txn_count')
            ->orderByDesc('last_date')
            ->orderBy('merchant_key')
            ->limit($limit + 1)
            ->get();

        $truncated = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return Response::structured([
            'applied_filters' => [
                'query' => $validated['query'],
                ...$this->appliedFilters($filters),
                'limit' => $limit,
            ],
            'count' => $rows->count(),
            'truncated' => $truncated,
            'merchants' => $rows->map(function ($row) {
                $names = array_filter(array_merge(
                    json_decode((string) $row->merchant_names, true) ?? [],
                    json_decode((string) $row->names, true) ?? [],
                ));
                sort($names);

                return [
                    'merchant' => $row->merchant_key,
                    'name_variants' => array_slice(array_values(array_unique($names)), 0, 5),
                    'transaction_count' => (int) $row->txn_count,
                    'total_amount' => $this->money($row->total),
                    'first_date' => $row->first_date,
                    'last_date' => $row->last_date,
                ];
            })->values()->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->min(2)->max(100)
                ->description('Words from the store/biller name; every word must appear (in any order) in the merchant name or raw transaction description.'),
            ...$this->transactionFilterSchema($schema, except: ['merchant']),
            'limit' => $schema->integer()->min(1)->max(50)
                ->description('Max distinct merchants to return (1-50). Default 20.'),
        ];
    }
}
