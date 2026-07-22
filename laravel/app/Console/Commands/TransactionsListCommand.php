<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;

class TransactionsListCommand extends Command
{
    // Qualified with the table name: withEffectiveCategory() left-joins
    // `categories`, which also has a `name` column, so an unqualified
    // `name`/`date` order-by would be ambiguous in Postgres.
    private const SORTABLE_COLUMNS = [
        'date' => 'transactions.date',
        'amount' => 'transactions.amount',
        'name' => 'transactions.name',
        'merchantName' => 'transactions.merchant_name',
    ];

    private const DEFAULT_LIMIT = 50;

    private const MAX_LIMIT = 200;

    protected $signature = 'transactions:list
        {--limit= : Max rows to return (1-200, default 50)}
        {--offset= : Rows to skip (default 0)}
        {--sort-by= : Sort column: date|amount|name|merchantName (default date)}
        {--order= : Sort direction: asc|desc (default desc)}
        {--json : Output machine-readable JSON}';

    protected $description = 'List transactions, paginated and sortable';

    public function handle(): int
    {
        $sortBy = $this->option('sort-by') ?? 'date';
        $column = self::SORTABLE_COLUMNS[$sortBy] ?? null;
        if ($column === null) {
            $this->error(sprintf(
                'Invalid --sort-by "%s". Must be one of: %s',
                $sortBy,
                implode(', ', array_keys(self::SORTABLE_COLUMNS)),
            ));

            return self::FAILURE;
        }

        $order = $this->option('order') ?? 'desc';
        if (! in_array($order, ['asc', 'desc'], true)) {
            $this->error('Invalid --order. Must be one of: asc, desc');

            return self::FAILURE;
        }

        $limit = min(max((int) ($this->option('limit') ?? self::DEFAULT_LIMIT), 1), self::MAX_LIMIT);
        $offset = max((int) ($this->option('offset') ?? 0), 0);

        $rows = Transaction::withEffectiveCategory()
            ->with('account:id,name')
            ->orderBy($column, $order)
            ->orderBy('transactions.id', 'asc')
            ->limit($limit)
            ->offset($offset)
            ->get();

        $total = Transaction::count();

        $items = $rows->map(fn ($row) => [
            'id' => $row->id,
            'accountId' => $row->account_id,
            'accountName' => $row->account?->name,
            'date' => optional($row->date)->toDateString(),
            'name' => $row->name,
            'merchantName' => $row->merchant_name,
            // Transaction's `amount` cast is `decimal:2`, which Eloquent already
            // renders as a fixed-precision string — no need to touch raw storage.
            'amount' => $row->amount,
            'isoCurrencyCode' => $row->iso_currency_code,
            'pending' => (bool) $row->pending,
            'categorySlug' => $row->effective_category_slug,
            'categoryName' => $row->effective_category_name,
        ])->values();

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'items' => $items->all(),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ]));

            return self::SUCCESS;
        }

        if ($items->isEmpty()) {
            $this->info('No transactions found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Date', 'Name', 'Merchant', 'Amount', 'Category', 'Account'],
            $items->map(fn ($item) => [
                $item['date'],
                $item['name'],
                $item['merchantName'],
                $item['amount'],
                $item['categoryName'],
                $item['accountName'],
            ])->all(),
        );

        $this->info(sprintf('Showing %d of %d transactions (limit=%d offset=%d)', $items->count(), $total, $limit, $offset));

        return self::SUCCESS;
    }
}
