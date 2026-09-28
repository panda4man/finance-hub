<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Concerns\InteractsWithTransactionFilters;
use App\Services\TransactionQueryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_transactions')]
#[IsReadOnly]
#[IsIdempotent]
#[IsOpenWorld(false)]
#[Description(<<<'MARKDOWN'
    Search the authenticated user's transactions with flexible filters, sorting and paging.
    Call search_merchants first to find the exact merchant string to pass here.

    Examples:
    - "When was the last time I purchased at Target?" -> {merchant:"target", direction:"outflow", sort:"date", order:"desc", limit:1}
    - "Largest credit in the last year" -> {direction:"inflow", date_from:"<today-1y>", sort:"magnitude", order:"desc", limit:1}
    MARKDOWN
)]
class SearchTransactions extends Tool
{
    use InteractsWithTransactionFilters;

    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $this->resolveUser($request);

        $validated = $request->validate([
            ...$this->transactionFilterRules(),
            'sort' => ['nullable', 'in:date,amount,magnitude'],
            'order' => ['nullable', 'in:asc,desc'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $filters = $this->transactionFiltersFrom($validated);
        $sort = $validated['sort'] ?? 'date';
        $order = $validated['order'] ?? 'desc';
        $limit = $validated['limit'] ?? 25;

        $service = app(TransactionQueryService::class);
        $query = $service->applyFilters($service->ownedQuery($user), $filters);

        $stats = (clone $query)->toBase()->select([])
            ->selectRaw('COUNT(*) AS c')
            ->selectRaw('COALESCE(SUM(transactions.amount), 0) AS s')
            ->first();

        match ($sort) {
            'amount' => $query->orderBy('transactions.amount', $order),
            'magnitude' => $query->orderByRaw('ABS(transactions.amount) '.($order === 'asc' ? 'ASC' : 'DESC')),
            default => $query->orderBy('transactions.date', $order),
        };

        $query->orderByDesc('transactions.date')->orderByDesc('transactions.id');

        $transactions = $query->limit($limit)->get();

        $totalMatching = (int) $stats->c;
        $returned = $transactions->count();

        return Response::structured([
            'applied_filters' => [
                ...$this->appliedFilters($filters),
                'sort' => $sort,
                'order' => $order,
                'limit' => $limit,
            ],
            'total_matching' => $totalMatching,
            'matching_net_amount' => $this->money($stats->s),
            'returned' => $returned,
            'truncated' => $totalMatching > $returned,
            'transactions' => $transactions->map(fn ($t) => $this->presentTransaction($t))->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            ...$this->transactionFilterSchema($schema),
            'sort' => $schema->string()->enum(['date', 'amount', 'magnitude'])
                ->description('Sort by date, signed amount, or amount magnitude. Default date.'),
            'order' => $schema->string()->enum(['asc', 'desc'])
                ->description('Sort direction. Default desc.'),
            'limit' => $schema->integer()->min(1)->max(100)
                ->description('Max transactions to return (1-100). Default 25.'),
        ];
    }
}
