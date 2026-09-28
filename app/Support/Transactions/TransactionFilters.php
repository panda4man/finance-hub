<?php

declare(strict_types=1);

namespace App\Support\Transactions;

use App\Enums\AccountType;
use App\Enums\AmountSign;
use Carbon\CarbonImmutable;

final readonly class TransactionFilters
{
    /**
     * @param  list<AccountType>  $accountTypes
     * @param  list<string>  $accountIds
     * @param  list<string>  $categorySlugs
     */
    public function __construct(
        public ?CarbonImmutable $dateFrom = null,
        public ?CarbonImmutable $dateTo = null,
        public ?string $amountMin = null,
        public ?string $amountMax = null,
        public AmountSign $direction = AmountSign::Any,
        public array $accountTypes = [],
        public array $accountIds = [],
        public ?string $institution = null,
        public ?string $merchant = null,
        public array $categorySlugs = [],
        public bool $includePending = false,
        public bool $includeHidden = false,
    ) {}

    /**
     * @param  array{
     *     date_from?: string|\DateTimeInterface|null,
     *     date_to?: string|\DateTimeInterface|null,
     *     amount_min?: int|float|string|null,
     *     amount_max?: int|float|string|null,
     *     direction?: AmountSign|string|null,
     *     account_types?: list<AccountType|string>|null,
     *     account_ids?: list<string>|null,
     *     institution?: string|null,
     *     merchant?: string|null,
     *     category_slugs?: list<string>|null,
     *     include_pending?: bool|int|string|null,
     *     include_hidden?: bool|int|string|null,
     * }  $validated
     */
    public static function fromArray(array $validated): self
    {
        return new self(
            dateFrom: self::parseDate($validated['date_from'] ?? null),
            dateTo: self::parseDate($validated['date_to'] ?? null),
            amountMin: self::parseAmount($validated['amount_min'] ?? null),
            amountMax: self::parseAmount($validated['amount_max'] ?? null),
            direction: self::parseDirection($validated['direction'] ?? null),
            accountTypes: self::parseAccountTypes($validated['account_types'] ?? []),
            accountIds: self::parseList($validated['account_ids'] ?? []),
            institution: self::parseString($validated['institution'] ?? null),
            merchant: self::parseString($validated['merchant'] ?? null),
            categorySlugs: self::parseList($validated['category_slugs'] ?? []),
            includePending: self::parseBool($validated['include_pending'] ?? null),
            includeHidden: self::parseBool($validated['include_hidden'] ?? null),
        );
    }

    private static function parseDate(string|\DateTimeInterface|null $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->startOfDay();
    }

    private static function parseAmount(int|float|string|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw new \InvalidArgumentException('Amount must be a non-negative number.');
        }

        return (string) $value;
    }

    private static function parseDirection(AmountSign|string|null $value): AmountSign
    {
        if ($value === null) {
            return AmountSign::Any;
        }

        return $value instanceof AmountSign ? $value : AmountSign::from($value);
    }

    /**
     * @param  list<AccountType|string>  $values
     * @return list<AccountType>
     */
    private static function parseAccountTypes(array $values): array
    {
        $types = array_map(
            fn (AccountType|string $v): AccountType => $v instanceof AccountType ? $v : AccountType::from($v),
            $values,
        );

        $unique = [];
        foreach ($types as $type) {
            $unique[$type->value] = $type;
        }

        return array_values($unique);
    }

    /**
     * @param  list<string>  $values
     * @return list<string>
     */
    private static function parseList(array $values): array
    {
        return array_values(array_unique($values));
    }

    private static function parseString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function parseBool(bool|int|string|null $value): bool
    {
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
