<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use App\Enums\AccountType;
use App\Enums\AmountSign;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Transactions\TransactionFilters;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;

trait InteractsWithTransactionFilters
{
    /**
     * Normalizes a transaction's merchant identity: prefer merchant_name,
     * falling back to the raw name when merchant_name is null or blank.
     */
    public const MERCHANT_KEY_SQL = "LOWER(TRIM(COALESCE(NULLIF(transactions.merchant_name, ''), transactions.name)))";

    /**
     * @param  list<string>  $except
     * @return array<string, Type>
     */
    protected function transactionFilterSchema(JsonSchema $schema, array $except = []): array
    {
        $fields = [
            'date_from' => fn (): Type => $schema->string()->format('date')
                ->description('Inclusive start date (YYYY-MM-DD).'),
            'date_to' => fn (): Type => $schema->string()->format('date')
                ->description('Inclusive end date (YYYY-MM-DD).'),
            'amount_min' => fn (): Type => $schema->number()->min(0)->max(999999999999.99)
                ->description('Minimum transaction amount magnitude (ignores sign; combine with direction to filter spending vs income).'),
            'amount_max' => fn (): Type => $schema->number()->min(0)->max(999999999999.99)
                ->description('Maximum transaction amount magnitude (ignores sign; combine with direction to filter spending vs income).'),
            'direction' => fn (): Type => $schema->string()->enum(['any', 'outflow', 'inflow'])
                ->description('outflow = spending/debits (positive amounts); inflow = income/refunds/credits (negative amounts); any = both.'),
            'account_types' => fn (): Type => $schema->array()
                ->items($schema->string()->enum(array_column(AccountType::cases(), 'value')))
                ->description('Filter to these account types. Debit-card purchases post to a "checking" account — there is no separate debit type.'),
            'account_ids' => fn (): Type => $schema->array()->items($schema->string()->format('uuid'))
                ->description('Filter to these specific account ids. Use list_accounts to resolve names to ids.'),
            'institution' => fn (): Type => $schema->string()
                ->description('An institution id (uuid) or a case-insensitive name fragment, e.g. "chase".'),
            'merchant' => fn (): Type => $schema->string()
                ->description('A case-insensitive substring matched against the transaction merchant name or raw description. Use search_merchants first to find the exact string to pass here.'),
            'categories' => fn (): Type => $schema->array()->items($schema->string())
                ->description('Effective category slugs (see the category.slug field on returned transactions).'),
            'include_pending' => fn (): Type => $schema->boolean()
                ->description('Include pending (not yet settled) transactions. Default false.'),
            'include_hidden' => fn (): Type => $schema->boolean()
                ->description('Include transactions the user has hidden. Default false.'),
        ];

        foreach ($except as $key) {
            unset($fields[$key]);
        }

        return array_map(fn (\Closure $make): Type => $make(), $fields);
    }

    /**
     * @param  list<string>  $except
     * @return array<string, list<mixed>>
     */
    protected function transactionFilterRules(array $except = []): array
    {
        $rules = [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'amount_min' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'amount_max' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'direction' => ['nullable', Rule::enum(AmountSign::class)],
            'account_types' => ['nullable', 'array', 'max:6'],
            'account_types.*' => [Rule::enum(AccountType::class)],
            'account_ids' => ['nullable', 'array', 'max:50'],
            'account_ids.*' => ['uuid'],
            'institution' => ['nullable', 'string', 'max:255'],
            'merchant' => ['nullable', 'string', 'max:255'],
            'categories' => ['nullable', 'array', 'max:50'],
            'categories.*' => ['string', 'max:100'],
            'include_pending' => ['nullable', 'boolean'],
            'include_hidden' => ['nullable', 'boolean'],
        ];

        foreach ($except as $key) {
            unset($rules[$key], $rules[$key.'.*']);
        }

        return $rules;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    protected function transactionFiltersFrom(array $validated): TransactionFilters
    {
        $mapped = Arr::only($validated, [
            'date_from', 'date_to', 'amount_min', 'amount_max', 'direction',
            'account_types', 'account_ids', 'institution', 'merchant',
            'include_pending', 'include_hidden',
        ]);

        $mapped['category_slugs'] = $validated['categories'] ?? [];

        return TransactionFilters::fromArray($mapped);
    }

    /**
     * @return array<string, mixed>
     */
    protected function appliedFilters(TransactionFilters $filters): array
    {
        return [
            'date_from' => $filters->dateFrom?->toDateString(),
            'date_to' => $filters->dateTo?->toDateString(),
            'amount_min' => $filters->amountMin,
            'amount_max' => $filters->amountMax,
            'direction' => $filters->direction->value,
            'account_types' => array_map(fn (AccountType $t): string => $t->value, $filters->accountTypes),
            'account_ids' => $filters->accountIds,
            'institution' => $filters->institution,
            'merchant' => $filters->merchant,
            'categories' => $filters->categorySlugs,
            'include_pending' => $filters->includePending,
            'include_hidden' => $filters->includeHidden,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentTransaction(Transaction $transaction): array
    {
        $amount = (float) $transaction->amount;
        $direction = match (true) {
            $amount > 0 => 'outflow',
            $amount < 0 => 'inflow',
            default => 'zero',
        };

        return [
            ...(new TransactionResource($transaction))->resolve(),
            'direction' => $direction,
        ];
    }

    protected function resolveUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    protected function money(string|int|float|null $value): string
    {
        return number_format((float) ($value ?? 0), 2, '.', '');
    }
}
