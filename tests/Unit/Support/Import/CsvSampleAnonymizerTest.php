<?php

use App\Support\Import\CsvSampleAnonymizer;

it('leaves date and type columns unmasked', function () {
    $anonymizer = new CsvSampleAnonymizer;

    $result = $anonymizer->anonymize(
        ['Date', 'Type'],
        [['Date' => '2026-01-01', 'Type' => 'debit']],
        ['date' => 'Date', 'type' => 'Type'],
    );

    expect($result['rows'][0])->toBe(['2026-01-01', 'debit']);
});

it('masks description, amount, balance, and external_id columns', function () {
    $anonymizer = new CsvSampleAnonymizer;

    $result = $anonymizer->anonymize(
        ['Description', 'Amount', 'Balance', 'Confirmation #'],
        [['Description' => 'Coffee Shop', 'Amount' => '-4.25', 'Balance' => '102.10', 'Confirmation #' => 'A1B2']],
        ['description' => 'Description', 'amount' => 'Amount', 'balance' => 'Balance', 'external_id' => 'Confirmation #'],
    );

    expect($result['rows'][0])->toBe(['XXXXXX XXXX', '-9.99', '999.99', 'X9X9']);
});

it('masks any column not mapped to a role', function () {
    $anonymizer = new CsvSampleAnonymizer;

    $result = $anonymizer->anonymize(
        ['Date', 'Account Number'],
        [['Date' => '2026-01-01', 'Account Number' => '000123456']],
        ['date' => 'Date'],
    );

    expect($result['rows'][0])->toBe(['2026-01-01', '999999999']);
});

it('preserves the header row verbatim', function () {
    $anonymizer = new CsvSampleAnonymizer;

    $result = $anonymizer->anonymize(
        ['Date', 'Description'],
        [['Date' => '2026-01-01', 'Description' => 'Coffee']],
        ['date' => 'Date', 'description' => 'Description'],
    );

    expect($result['header'])->toBe(['Date', 'Description']);
});

it('caps the stored rows at 4, even when given more sample rows', function () {
    $anonymizer = new CsvSampleAnonymizer;

    $sampleRows = [
        ['Date' => '2026-01-01'],
        ['Date' => '2026-01-02'],
        ['Date' => '2026-01-03'],
        ['Date' => '2026-01-04'],
        ['Date' => '2026-01-05'],
    ];

    $result = $anonymizer->anonymize(['Date'], $sampleRows, ['date' => 'Date']);

    expect($result['rows'])->toHaveCount(4);
    expect($result['rows'][3])->toBe(['2026-01-04']);
});

it('handles missing cells and non-letter/digit punctuation without erroring', function () {
    $anonymizer = new CsvSampleAnonymizer;

    $result = $anonymizer->anonymize(
        ['Description', 'Amount'],
        [['Description' => 'Coffee & Tea, Ltd.']],
        ['description' => 'Description'],
    );

    expect($result['rows'][0])->toBe(['XXXXXX & XXX, XXX.', '']);
});
