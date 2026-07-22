<?php

use App\Models\ImportTemplate;
use App\Support\Import\GenericCsvParser;
use Database\Seeders\ImportTemplateSeeder;

beforeEach(fn () => $this->seed(ImportTemplateSeeder::class));

function createChaseTestCsvFile(string $content): string
{
    $path = sys_get_temp_dir().'/'.uniqid('chase_test_').'.csv';
    file_put_contents($path, $content);

    return $path;
}

function chaseImportTemplate(): ImportTemplate
{
    return ImportTemplate::where('name', 'Chase checking')->firstOrFail();
}

it('parses a valid Chase CSV export correctly', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE SHOP,-10.50,Purchase,1000.00,
CREDIT,07/21/2026,DEPOSIT,500.00,Deposit,1010.50,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2);
        expect($result['failures'])->toBeEmpty();

        $debitRow = $result['rows'][0];
        expect($debitRow->description)->toBe('COFFEE SHOP');
        expect($debitRow->amount)->toBe(10.50); // sign-flipped from -10.50 (CSV debit)
        expect($debitRow->postingDate)->toBe('2026-07-22');
        expect($debitRow->balance)->toBe(1000.00);

        $creditRow = $result['rows'][1];
        expect($creditRow->description)->toBe('DEPOSIT');
        expect($creditRow->amount)->toBe(-500.00); // sign-flipped from 500.00 (CSV credit)
        expect($creditRow->postingDate)->toBe('2026-07-21');
    } finally {
        @unlink($path);
    }
});

it('sign-flips amounts: CSV debit becomes positive outflow', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,PURCHASE,-10.50,Purchase,1000.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        $row = $result['rows'][0];
        // CSV had -10.50 (debit), should flip to +10.50 (outflow)
        expect($row->amount)->toBe(10.50);
    } finally {
        @unlink($path);
    }
});

it('sign-flips amounts: CSV credit becomes negative inflow', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
CREDIT,07/21/2026,SALARY,3000.00,Deposit,1000.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        $row = $result['rows'][0];
        // CSV had 3000.00 (credit), should flip to -3000.00 (inflow)
        expect($row->amount)->toBe(-3000.00);
    } finally {
        @unlink($path);
    }
});

it('collects malformed dates as failures without aborting parse', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,10.50,Purchase,1000.00,
DEBIT,13/45/2026,INVALID DATE,-20.00,Purchase,990.00,
DEBIT,07/20/2026,LUNCH,-15.00,Purchase,1005.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2); // valid rows only
        expect($result['failures'])->toHaveCount(1);
        expect($result['failures'][0])->toContain('unparseable posting date');

        $validRow1 = $result['rows'][0];
        expect($validRow1->description)->toBe('COFFEE');

        $validRow2 = $result['rows'][1];
        expect($validRow2->description)->toBe('LUNCH');
    } finally {
        @unlink($path);
    }
});

it('collects malformed amounts as failures without aborting parse', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
DEBIT,07/21/2026,BAD AMOUNT,abc,Purchase,1010.00,
DEBIT,07/20/2026,LUNCH,-15.00,Purchase,1025.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2);
        expect($result['failures'])->toHaveCount(1);
        expect($result['failures'][0])->toContain('unparseable amount');
    } finally {
        @unlink($path);
    }
});

it('rejects CSV with wrong header', function () {
    $csv = <<<'CSV'
Wrong,Header,Names,Here,Not,Chase,CSV
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unrecognized CSV header');
        $parser->parse(chaseImportTemplate(), 'acc-1', $path);
    } finally {
        @unlink($path);
    }
});

it('generates deterministic external_transaction_id from account, date, amount, description', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,999.50,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'])->toHaveCount(2);

        // Same day, amount, description = same group but different occurrence
        expect($result['rows'][0]->externalTransactionId)->not->toBe($result['rows'][1]->externalTransactionId);

        // Re-parse the same file, should get the same ids
        $result2 = $parser->parse(chaseImportTemplate(), 'acc-1', $path);
        expect($result2['rows'][0]->externalTransactionId)->toBe($result['rows'][0]->externalTransactionId);
        expect($result2['rows'][1]->externalTransactionId)->toBe($result['rows'][1]->externalTransactionId);
    } finally {
        @unlink($path);
    }
});

it('handles blank balance fields gracefully', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,PURCHASE,-10.50,Purchase,,
CREDIT,07/21/2026,DEPOSIT,500.00,Deposit,1000.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'][0]->balance)->toBeNull();
        expect($result['rows'][1]->balance)->toBe(1000.00);
    } finally {
        @unlink($path);
    }
});

it('trims whitespace from description and details', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT ,07/22/2026,  COFFEE SHOP  ,-10.50,Purchase,1000.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'][0]->description)->toBe('COFFEE SHOP');
        expect($result['rows'][0]->detailsType)->toBe('DEBIT');
    } finally {
        @unlink($path);
    }
});

it('catches date overflow like 13/45/2026 that createFromFormat would roll over', function () {
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,13/45/2026,BAD DATE,-10.50,Purchase,1000.00,
CSV;
    $path = createChaseTestCsvFile($csv);

    try {
        $parser = new GenericCsvParser;
        $result = $parser->parse(chaseImportTemplate(), 'acc-1', $path);

        expect($result['rows'])->toBeEmpty();
        expect($result['failures'])->toHaveCount(1);
        expect($result['failures'][0])->toContain('unparseable posting date');
    } finally {
        @unlink($path);
    }
});
