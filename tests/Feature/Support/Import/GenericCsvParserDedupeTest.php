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

it('rejects the import upfront when a core parsing role (date) is unmapped, even if excluded from dedupe_columns', function () {
    // dedupe_strategy only needs 'amount' per its own config, but date is
    // still required for parsing every row regardless of dedupe_columns.
    $template = makeImportTemplate([
        'column_mapping' => [
            'description' => 'Description',
            'amount' => 'Amount',
            // 'date' deliberately unmapped.
        ],
        'dedupe_columns' => ['amount'],
        'header_signature' => ['Description', 'Amount'],
    ]);

    $csv = <<<'CSV'
Description,Amount
COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;

        expect(fn () => $parser->parse($template, 'acc-1', $path))
            ->toThrow(RuntimeException::class, 'date');
    } finally {
        @unlink($path);
    }
});

it('rejects the import upfront when a core parsing role is unmapped under the external_id strategy', function () {
    $template = makeImportTemplate([
        'column_mapping' => [
            'description' => 'Description',
            'amount' => 'Amount',
            'external_id' => 'Confirmation #',
            // 'date' deliberately unmapped.
        ],
        'dedupe_strategy' => DedupeStrategy::ExternalId,
        'dedupe_columns' => null,
        'header_signature' => ['Description', 'Amount', 'Confirmation #'],
    ]);

    $csv = <<<'CSV'
Description,Amount,Confirmation #
COFFEE,-10.50,ABC123
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;

        expect(fn () => $parser->parse($template, 'acc-1', $path))
            ->toThrow(RuntimeException::class, 'date');
    } finally {
        @unlink($path);
    }
});

it('rejects a stale/invalid role name in dedupe_columns with a clean error instead of crashing', function () {
    $template = makeImportTemplate([
        // Simulates a role that was renamed/removed after this template was
        // saved (or a hand-edited row) — must fail cleanly, not throw an
        // uncaught ValueError from ImportColumnRole::from().
        'dedupe_columns' => ['date', 'amount', 'description', 'not_a_real_role'],
    ]);

    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;

        expect(fn () => $parser->parse($template, 'acc-1', $path))
            ->toThrow(RuntimeException::class, 'not_a_real_role');
    } finally {
        @unlink($path);
    }
});

it('rejects the import upfront when external_id strategy has no external_id mapping', function () {
    $template = makeImportTemplate([
        'dedupe_strategy' => DedupeStrategy::ExternalId,
        'dedupe_columns' => null,
        // column_mapping deliberately omits 'external_id'.
    ]);

    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;

        expect(fn () => $parser->parse($template, 'acc-1', $path))
            ->toThrow(RuntimeException::class, 'external_id');
    } finally {
        @unlink($path);
    }
});

it('rejects the import upfront when a composite dedupe column is not mapped', function () {
    $template = makeImportTemplate([
        // 'type' is not a key in column_mapping.
        'dedupe_columns' => ['date', 'amount', 'description', 'type'],
    ]);

    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;

        expect(fn () => $parser->parse($template, 'acc-1', $path))
            ->toThrow(RuntimeException::class, 'type');
    } finally {
        @unlink($path);
    }
});

it('still rejects a file whose header omits a mapped dedupe column', function () {
    $template = makeImportTemplate([
        'column_mapping' => [
            'date' => 'Date',
            'description' => 'Description',
            'amount' => 'Amount',
            'type' => 'Type',
        ],
        'dedupe_columns' => ['date', 'amount', 'description', 'type'],
    ]);

    // Header lacks the mapped "Type" column entirely.
    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;

        expect(fn () => $parser->parse($template, 'acc-1', $path))
            ->toThrow(RuntimeException::class, 'expected a column named "Type"');
    } finally {
        @unlink($path);
    }
});

it('does not throw for a fully-mapped composite template', function () {
    $template = makeImportTemplate();

    $csv = <<<'CSV'
Date,Description,Amount
2026-07-22,COFFEE,-10.50
CSV;
    $path = createDedupeTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse($template, 'acc-1', $path);

        expect($result['rows'])->toHaveCount(1);
        expect($result['failures'])->toBeEmpty();
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
