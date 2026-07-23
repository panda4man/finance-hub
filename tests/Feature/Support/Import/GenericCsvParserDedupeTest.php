<?php

use App\Enums\DedupeStrategy;
use App\Models\ImportTemplate;
use App\Support\Import\GenericCsvParser;

function createDedupeTestCsvFile(string $content): string
{
    $path = sys_get_temp_dir().'/'.uniqid('dedupe_test_').'.csv';
    file_put_contents($path, $content);

    return $path;
}

function makeImportTemplate(array $overrides = []): ImportTemplate
{
    return ImportTemplate::create(array_merge([
        'institution_id' => null,
        'name' => 'Test template',
        'column_mapping' => [
            'date' => 'Date',
            'description' => 'Description',
            'amount' => 'Amount',
        ],
        'date_format' => 'Y-m-d',
        'flip_amount_sign' => false,
        'dedupe_strategy' => DedupeStrategy::Composite,
        'dedupe_columns' => ['date', 'amount', 'description'],
        'header_signature' => ['Date', 'Description', 'Amount'],
        'is_seeded' => false,
    ], $overrides));
}

it('uses a mapped external_id column directly as the idempotency key when dedupe_strategy is external_id', function () {
    $template = makeImportTemplate([
        'column_mapping' => [
            'date' => 'Date',
            'description' => 'Description',
            'amount' => 'Amount',
            'external_id' => 'Confirmation #',
        ],
        'header_signature' => ['Date', 'Description', 'Amount', 'Confirmation #'],
        'dedupe_strategy' => DedupeStrategy::ExternalId,
        'dedupe_columns' => null,
    ]);

    $csv = <<<'CSV'
Date,Description,Amount,Confirmation #
2026-07-22,COFFEE,-10.50,ABC123
2026-07-21,LUNCH,-5.00,XYZ789
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse($template, 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2);
        expect($result['failures'])->toBeEmpty();

        // Re-parsing produces the same ids (stable), and different confirmation
        // numbers produce different ids even though nothing else about the two
        // synthetic rows below would differ under the composite strategy.
        $again = $parser->parse($template, 'acc-1', $path);
        expect($again['rows'][0]->externalTransactionId)->toBe($result['rows'][0]->externalTransactionId);
        expect($result['rows'][0]->externalTransactionId)->not->toBe($result['rows'][1]->externalTransactionId);
    } finally {
        @unlink($path);
    }
});

it('distinguishes rows by external_id even when date/amount/description are identical', function () {
    $template = makeImportTemplate([
        'column_mapping' => [
            'date' => 'Date',
            'description' => 'Description',
            'amount' => 'Amount',
            'external_id' => 'Confirmation #',
        ],
        'header_signature' => ['Date', 'Description', 'Amount', 'Confirmation #'],
        'dedupe_strategy' => DedupeStrategy::ExternalId,
        'dedupe_columns' => null,
    ]);

    $csv = <<<'CSV'
Date,Description,Amount,Confirmation #
2026-07-22,COFFEE,-10.50,ID-1
2026-07-22,COFFEE,-10.50,ID-2
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse($template, 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2);
        expect($result['rows'][0]->externalTransactionId)->not->toBe($result['rows'][1]->externalTransactionId);
    } finally {
        @unlink($path);
    }
});

it('fails a row when external_id strategy is set but the mapped column is blank', function () {
    $template = makeImportTemplate([
        'column_mapping' => [
            'date' => 'Date',
            'description' => 'Description',
            'amount' => 'Amount',
            'external_id' => 'Confirmation #',
        ],
        'header_signature' => ['Date', 'Description', 'Amount', 'Confirmation #'],
        'dedupe_strategy' => DedupeStrategy::ExternalId,
        'dedupe_columns' => null,
    ]);

    $csv = <<<'CSV'
Date,Description,Amount,Confirmation #
2026-07-22,COFFEE,-10.50,
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse($template, 'acc-1', $path);

        expect($result['rows'])->toBeEmpty();
        expect($result['failures'])->toHaveCount(1);
        expect($result['failures'][0])->toContain('missing external id');
    } finally {
        @unlink($path);
    }
});

it('honors a custom composite dedupe_columns subset instead of the default three', function () {
    // Only 'amount' composes the key here — two different-description rows
    // with the same amount collide into occurrence 0/1 of the same group.
    $template = makeImportTemplate([
        'dedupe_columns' => ['amount'],
    ]);

    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
2026-07-21,SOMETHING ELSE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse($template, 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2);
        // Different descriptions/dates but same amount-only key: ids must
        // still differ via the occurrence index, not collide outright.
        expect($result['rows'][0]->externalTransactionId)->not->toBe($result['rows'][1]->externalTransactionId);
    } finally {
        @unlink($path);
    }
});

it('falls back to the default composite shape when dedupe_columns is null', function () {
    $template = makeImportTemplate(['dedupe_columns' => null]);

    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $withDefault = makeImportTemplate(['name' => 'Explicit default', 'dedupe_columns' => ['date', 'amount', 'description']]);

        $result = $parser->parse($template, 'acc-1', $path);
        $resultExplicit = $parser->parse($withDefault, 'acc-1', $path);

        expect($result['rows'][0]->externalTransactionId)->toBe($resultExplicit['rows'][0]->externalTransactionId);
    } finally {
        @unlink($path);
    }
});
