<?php

use App\Enums\AccountType;
use App\Enums\AmountSign;
use App\Support\Transactions\TransactionFilters;
use Carbon\CarbonImmutable;

it('defaults every field when given an empty array', function () {
    $filters = TransactionFilters::fromArray([]);

    expect($filters->dateFrom)->toBeNull();
    expect($filters->dateTo)->toBeNull();
    expect($filters->amountMin)->toBeNull();
    expect($filters->amountMax)->toBeNull();
    expect($filters->institution)->toBeNull();
    expect($filters->merchant)->toBeNull();
    expect($filters->direction)->toBe(AmountSign::Any);
    expect($filters->accountTypes)->toBe([]);
    expect($filters->accountIds)->toBe([]);
    expect($filters->categorySlugs)->toBe([]);
    expect($filters->includePending)->toBeFalse();
    expect($filters->includeHidden)->toBeFalse();
});

it('parses date strings into CarbonImmutable at start of day', function () {
    $filters = TransactionFilters::fromArray(['date_from' => '2026-09-01']);

    expect($filters->dateFrom)->toBeInstanceOf(CarbonImmutable::class);
    expect($filters->dateFrom->toDateString())->toBe('2026-09-01');
});

it('accepts direction as a string or an enum instance', function () {
    expect(TransactionFilters::fromArray(['direction' => 'outflow'])->direction)->toBe(AmountSign::Outflow);
    expect(TransactionFilters::fromArray(['direction' => AmountSign::Inflow])->direction)->toBe(AmountSign::Inflow);
    expect(TransactionFilters::fromArray(['direction' => null])->direction)->toBe(AmountSign::Any);
});

it('throws on an unknown direction', function () {
    TransactionFilters::fromArray(['direction' => 'sideways']);
})->throws(ValueError::class);

it('maps account type strings to AccountType enums', function () {
    $filters = TransactionFilters::fromArray(['account_types' => ['checking', 'credit_card']]);

    expect($filters->accountTypes)->toBe([AccountType::Checking, AccountType::CreditCard]);
});

it('accepts AccountType enum instances directly', function () {
    $filters = TransactionFilters::fromArray(['account_types' => [AccountType::Savings]]);

    expect($filters->accountTypes)->toBe([AccountType::Savings]);
});

it('dedupes account types', function () {
    $filters = TransactionFilters::fromArray(['account_types' => ['checking', 'checking', AccountType::Checking]]);

    expect($filters->accountTypes)->toBe([AccountType::Checking]);
});

it('normalizes numeric amounts to strings', function () {
    expect(TransactionFilters::fromArray(['amount_min' => 10])->amountMin)->toBe('10');
    expect(TransactionFilters::fromArray(['amount_min' => '12.5'])->amountMin)->toBe('12.5');
    expect(TransactionFilters::fromArray(['amount_min' => 0])->amountMin)->toBe('0');
});

it('rejects a negative amount', function () {
    TransactionFilters::fromArray(['amount_min' => -5]);
})->throws(InvalidArgumentException::class);

it('rejects a non-numeric amount', function () {
    TransactionFilters::fromArray(['amount_max' => 'abc']);
})->throws(InvalidArgumentException::class);

it('treats blank merchant and institution as null', function () {
    $filters = TransactionFilters::fromArray(['merchant' => '  ', 'institution' => '   ']);

    expect($filters->merchant)->toBeNull();
    expect($filters->institution)->toBeNull();
});

it('trims merchant and institution', function () {
    $filters = TransactionFilters::fromArray(['merchant' => ' Costco ', 'institution' => ' Chase ']);

    expect($filters->merchant)->toBe('Costco');
    expect($filters->institution)->toBe('Chase');
});

it('coerces boolean-ish include flags', function () {
    foreach (['1', 'true', true, 1] as $truthy) {
        expect(TransactionFilters::fromArray(['include_pending' => $truthy])->includePending)->toBeTrue();
    }

    foreach (['0', 'false', false, null] as $falsy) {
        expect(TransactionFilters::fromArray(['include_hidden' => $falsy])->includeHidden)->toBeFalse();
    }
});

it('dedupes and reindexes id and slug arrays', function () {
    $filters = TransactionFilters::fromArray([
        'account_ids' => ['a', 'a', 'b'],
        'category_slugs' => ['x', 'x', 'y'],
    ]);

    expect($filters->accountIds)->toBe(['a', 'b']);
    expect($filters->categorySlugs)->toBe(['x', 'y']);
});

it('is readonly', function () {
    $filters = TransactionFilters::fromArray([]);

    $filters->merchant = 'nope';
})->throws(Error::class);
