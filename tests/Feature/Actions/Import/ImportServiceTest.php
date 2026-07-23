<?php

use App\Enums\ConnectionStatus;
use App\Enums\ImportStatus;
use App\Models\Connection;
use App\Models\ImportRun;
use App\Models\ImportTemplate;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ImportService;
use Database\Seeders\ImportTemplateSeeder;

beforeEach(fn () => $this->seed(ImportTemplateSeeder::class));

function createImportServiceTestCsvFile(string $content): string
{
    $path = sys_get_temp_dir().'/'.uniqid('import_service_test_').'.csv';
    file_put_contents($path, $content);

    return $path;
}

function chaseTemplateId(): string
{
    return ImportTemplate::where('name', 'Chase checking')->value('id');
}

it('ensures a manual connection exists for a user or finds existing one', function () {
    $user = User::factory()->create();

    $service = app(ImportService::class);
    $conn1 = $service->ensureManualConnection($user->id);
    $conn2 = $service->ensureManualConnection($user->id);

    expect($conn1->id)->toBe($conn2->id);
    expect($conn1->provider)->toBe('manual');
    expect($conn1->status)->toBe(ConnectionStatus::Active);
    expect(Connection::where('user_id', $user->id)->where('provider', 'manual')->count())->toBe(1);
});

it('creates a manual account with synthesized external_account_id', function () {
    $user = User::factory()->create();

    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Test Checking', '4321', 'checking');

    expect($account->name)->toBe('Test Checking');
    expect($account->mask)->toBe('4321');
    expect($account->type)->toBe('checking');
    expect($account->external_account_id)->toMatch('/^manual:/');
    expect($account->connection->provider)->toBe('manual');
    expect($account->connection->user_id)->toBe($user->id);
});

it('imports a valid CSV file and creates import run with success status', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CREDIT,07/21/2026,SALARY,2000.00,Deposit,1010.50,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        expect($run->status)->toBe(ImportStatus::Success);
        expect($run->added_count)->toBe(2);
        expect($run->duplicate_count)->toBe(0);
        expect($run->failed_count)->toBe(0);
        expect($run->row_count)->toBe(2);
        expect(Transaction::count())->toBe(2);
        expect($run->error_message)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('imports a CSV with malformed rows and sets status to Partial', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
DEBIT,13/45/2026,BADDATE,-20.00,Purchase,990.00,
DEBIT,07/20/2026,LUNCH,-15.00,Purchase,1005.00,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        expect($run->status)->toBe(ImportStatus::Partial);
        expect($run->added_count)->toBe(2);
        expect($run->failed_count)->toBe(1);
        expect($run->row_count)->toBe(3);
        expect(Transaction::count())->toBe(2);
        expect($run->error_message)->toContain('unparseable posting date');
    } finally {
        @unlink($path);
    }
});

it('sets status to Failed when all rows are malformed', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,13/45/2026,BADDATE,-20.00,Purchase,990.00,
DEBIT,99/99/2026,ALSOBAD,-25.00,Purchase,985.00,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        expect($run->status)->toBe(ImportStatus::Failed);
        expect($run->added_count)->toBe(0);
        expect($run->failed_count)->toBe(2);
        expect(Transaction::count())->toBe(0);
    } finally {
        @unlink($path);
    }
});

it('fails with error message when CSV has wrong header', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Wrong,Headers,Not,Chase,Format,At,All
DATA,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unrecognized CSV header');
        $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        // Verify the run was recorded as Failed
        $run = ImportRun::where('account_id', $account->id)->firstOrFail();
        expect($run->status)->toBe(ImportStatus::Failed);
        expect($run->error_message)->toContain('Unrecognized CSV header');
    } finally {
        @unlink($path);
    }
});

it('updates account balance from the newest dated row with a non-blank balance', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/23/2026,NEWEST,-5.00,Purchase,,
DEBIT,07/22/2026,MIDDLE,-10.00,Purchase,1000.00,
DEBIT,07/21/2026,OLDEST,-15.00,Purchase,1015.00,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        $account->refresh();
        // Should use the 07/22 row's balance, not the blank 07/23 row, and not the 07/21 row
        expect((float) $account->current_balance)->toBe(1000.00);
        expect($account->balances_updated_at->toDateString())->toBe('2026-07-22');
    } finally {
        @unlink($path);
    }
});

it('guards against regressing balance on overlapping/older imports', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    // First import: sets balance to 1000.00 on 07/22
    $csv1 = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.00,Purchase,1000.00,
CSV;
    $path1 = createImportServiceTestCsvFile($csv1);

    try {
        $run1 = $service->importFile($account->id, chaseTemplateId(), $path1, 'test1.csv');
        $account->refresh();
        expect((float) $account->current_balance)->toBe(1000.00);
        expect($account->balances_updated_at->toDateString())->toBe('2026-07-22');

        // Second import: older data from 07/20 should not regress the balance
        $csv2 = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/20/2026,LUNCH,-15.00,Purchase,500.00,
CSV;
        $path2 = createImportServiceTestCsvFile($csv2);

        try {
            $run2 = $service->importFile($account->id, chaseTemplateId(), $path2, 'test2.csv');
            $account->refresh();

            // Balance should not regress to 500.00
            expect((float) $account->current_balance)->toBe(1000.00);
            expect($account->balances_updated_at->toDateString())->toBe('2026-07-22');
        } finally {
            @unlink($path2);
        }
    } finally {
        @unlink($path1);
    }
});

it('handles same-day multi-row ordering: first-encountered balance wins', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    // Chase lists rows newest-first, so within the same day, the first row
    // encountered is chronologically the latest. That row's balance should win.
    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,PURCHASE1,-10.00,Purchase,1000.00,
DEBIT,07/22/2026,PURCHASE2,-5.00,Purchase,1010.00,
DEBIT,07/22/2026,PURCHASE3,-20.00,Purchase,1030.00,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        $account->refresh();
        // The first row's balance (1000.00) should be used, not the last (1030.00)
        expect((float) $account->current_balance)->toBe(1000.00);
    } finally {
        @unlink($path);
    }
});

it('handles import with no balance data', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.00,Purchase,,
DEBIT,07/21/2026,LUNCH,-5.00,Purchase,,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        $account->refresh();
        expect($account->current_balance)->toBeNull();
        expect($account->balances_updated_at)->toBeNull();
    } finally {
        @unlink($path);
    }
});

it('creates ImportRun with Running status initially, then updates with final status', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CSV;
    $path = createImportServiceTestCsvFile($csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateId(), $path, 'test.csv');

        expect($run->file_name)->toBe('test.csv');
        expect($run->status)->toBe(ImportStatus::Success);
        expect($run->finished_at)->not->toBeNull();
        expect($run->started_at)->not->toBeNull();
    } finally {
        @unlink($path);
    }
});
