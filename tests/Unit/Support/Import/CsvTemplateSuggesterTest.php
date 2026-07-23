<?php

use App\Enums\DedupeStrategy;
use App\Support\Import\CsvTemplateSuggester;
use App\Support\Import\DedupeKeyValidator;

it('suggests column mapping from recognizable header aliases', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Posting Date', 'Description', 'Amount', 'Balance'], []);

    expect($result['header_signature'])->toBe(['Posting Date', 'Description', 'Amount', 'Balance']);
    expect($result['column_mapping'])->toBe([
        'date' => 'Posting Date',
        'description' => 'Description',
        'amount' => 'Amount',
        'balance' => 'Balance',
    ]);
});

it('trims whitespace in header cells before matching aliases', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze([' Date ', ' Amount '], []);

    expect($result['header_signature'])->toBe(['Date', 'Amount']);
    expect($result['column_mapping'])->toBe([
        'date' => 'Date',
        'amount' => 'Amount',
    ]);
});

it('leaves unrecognized headers unmapped', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Some', 'Unknown', 'Bank', 'Format'], []);

    expect($result['column_mapping'])->toBe([]);
});

it('only assigns one header cell per role when two columns match the same alias list', function () {
    $suggester = new CsvTemplateSuggester;

    // Debit and Credit both alias to the "amount" role; only the first
    // should be claimed, leaving the second unmapped rather than
    // overwriting the mapping.
    $result = $suggester->analyze(['Date', 'Debit', 'Credit'], []);

    expect($result['column_mapping'])->toBe([
        'date' => 'Date',
        'amount' => 'Debit',
    ]);
});

it('defaults to the composite idempotency key when there is no external-id-like column', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Date', 'Description', 'Amount'], [
        ['Date' => '2026-01-01', 'Description' => 'Coffee', 'Amount' => '5.00'],
    ]);

    expect($result['dedupe_strategy'])->toBe(DedupeStrategy::Composite);
    expect($result['dedupe_columns'])->toBe(DedupeKeyValidator::DEFAULT_DEDUPE_COLUMNS);
});

it('suggests the external-id idempotency key when a mapped id column is unique and complete', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Date', 'Description', 'Amount', 'Confirmation #'], [
        ['Date' => '2026-01-01', 'Description' => 'Coffee', 'Amount' => '5.00', 'Confirmation #' => 'A1'],
        ['Date' => '2026-01-02', 'Description' => 'Lunch', 'Amount' => '12.00', 'Confirmation #' => 'A2'],
    ]);

    expect($result['dedupe_strategy'])->toBe(DedupeStrategy::ExternalId);
    expect($result['dedupe_columns'])->toBe([]);
});

it('falls back to composite when the id-like column has blank values', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Date', 'Description', 'Amount', 'Confirmation #'], [
        ['Date' => '2026-01-01', 'Description' => 'Coffee', 'Amount' => '5.00', 'Confirmation #' => 'A1'],
        ['Date' => '2026-01-02', 'Description' => 'Lunch', 'Amount' => '12.00', 'Confirmation #' => ''],
    ]);

    expect($result['dedupe_strategy'])->toBe(DedupeStrategy::Composite);
});

it('falls back to composite when the id-like column has duplicate values', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Date', 'Description', 'Amount', 'Confirmation #'], [
        ['Date' => '2026-01-01', 'Description' => 'Coffee', 'Amount' => '5.00', 'Confirmation #' => 'A1'],
        ['Date' => '2026-01-02', 'Description' => 'Lunch', 'Amount' => '12.00', 'Confirmation #' => 'A1'],
    ]);

    expect($result['dedupe_strategy'])->toBe(DedupeStrategy::Composite);
});

it('falls back to composite when there are no sample rows to analyze', function () {
    $suggester = new CsvTemplateSuggester;

    $result = $suggester->analyze(['Date', 'Description', 'Amount', 'Confirmation #'], []);

    expect($result['dedupe_strategy'])->toBe(DedupeStrategy::Composite);
});
