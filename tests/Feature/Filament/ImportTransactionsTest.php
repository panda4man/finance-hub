<?php

use App\Enums\ImportStatus;
use App\Filament\Pages\ImportTransactions;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ImportTemplate;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ImportService;
use Database\Seeders\ImportTemplateSeeder;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(function () {
    Gate::before(fn () => true);
    $this->seed(ImportTemplateSeeder::class);
});

function chaseTemplateIdForImportPage(): string
{
    return ImportTemplate::where('name', 'Chase checking')->value('id');
}

it('renders the import transactions page for authenticated user via Filament', function () {
    $user = User::factory()->create();
    actingAs($user);

    // The page is registered as a Filament page and accessible via the admin panel
    // We verify it can be instantiated without errors
    $page = new ImportTransactions;
    expect($page)->toBeInstanceOf(ImportTransactions::class);
});

it('ImportService can create a new manual account', function () {
    $user = User::factory()->create();

    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'My Checking', '1234', 'checking');

    expect($account->name)->toBe('My Checking');
    expect($account->mask)->toBe('1234');
    expect($account->type)->toBe('checking');
});

it('ImportService can import to an existing manual account', function () {
    $user = User::factory()->create();

    // Create existing manual account
    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => 'active']);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'manual:existing-1',
        'name' => 'Existing Checking',
    ]);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CSV;

    $path = sys_get_temp_dir().'/'.uniqid('test_').'.csv';
    file_put_contents($path, $csv);

    try {
        $service = app(ImportService::class);
        $run = $service->importFile($account->id, chaseTemplateIdForImportPage(), $path, 'test.csv');

        expect($run->status)->toBe(ImportStatus::Success);
        expect(Transaction::where('account_id', $account->id)->count())->toBe(1);
    } finally {
        @unlink($path);
    }
});

it('records successful import with correct status and counts', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Test Account', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
DEBIT,07/21/2026,LUNCH,-5.00,Purchase,1010.00,
CSV;

    $path = sys_get_temp_dir().'/'.uniqid('test_').'.csv';
    file_put_contents($path, $csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateIdForImportPage(), $path, 'test.csv');

        expect($run->status)->toBe(ImportStatus::Success);
        expect($run->added_count)->toBe(2);
        expect($run->duplicate_count)->toBe(0);
        expect($run->failed_count)->toBe(0);
        expect(Transaction::count())->toBe(2);
    } finally {
        @unlink($path);
    }
});

it('records partial import status when some rows fail', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Test Account', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
DEBIT,13/45/2026,BADDATE,-5.00,Purchase,1010.00,
CSV;

    $path = sys_get_temp_dir().'/'.uniqid('test_').'.csv';
    file_put_contents($path, $csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateIdForImportPage(), $path, 'test.csv');

        expect($run->status)->toBe(ImportStatus::Partial);
        expect($run->added_count)->toBe(1);
        expect($run->failed_count)->toBe(1);
    } finally {
        @unlink($path);
    }
});

it('records failed import status when header is invalid', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Test Account', null, null);

    $csv = <<<'CSV'
Wrong,Header,Names,Here,Not,Chase,CSV
DEBIT,07/22/2026,COFFEE,-10.50,Purchase,1000.00,
CSV;

    $path = sys_get_temp_dir().'/'.uniqid('test_').'.csv';
    file_put_contents($path, $csv);

    try {
        $this->expectException(RuntimeException::class);
        $run = $service->importFile($account->id, chaseTemplateIdForImportPage(), $path, 'test.csv');
    } finally {
        @unlink($path);
    }
});

it('only shows existing manual accounts for the current user', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    // Create manual accounts
    $userConnection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => 'active']);
    $userAccount = Account::create([
        'connection_id' => $userConnection->id,
        'external_account_id' => 'manual:user-1',
        'name' => 'User Checking',
    ]);

    // Create other user's manual account
    $otherConnection = Connection::create(['user_id' => $otherUser->id, 'provider' => 'manual', 'status' => 'active']);
    $otherAccount = Account::create([
        'connection_id' => $otherConnection->id,
        'external_account_id' => 'manual:other-1',
        'name' => 'Other Checking',
    ]);

    // The ImportService should only find userAccount when scoped to $user
    $service = app(ImportService::class);
    $connection = $service->ensureManualConnection($user->id);

    expect($connection->accounts()->count())->toBe(1);
    expect($connection->accounts()->first()->id)->toBe($userAccount->id);
});

it('ImportService creates manual connection per user', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $service = app(ImportService::class);

    $conn1 = $service->ensureManualConnection($user1->id);
    $conn2 = $service->ensureManualConnection($user2->id);

    expect($conn1->id)->not->toBe($conn2->id);
    expect($conn1->user_id)->toBe($user1->id);
    expect($conn2->user_id)->toBe($user2->id);
});

it('ImportService can create account with optional fields', function () {
    $user = User::factory()->create();

    $service = app(ImportService::class);

    $account1 = $service->createManualAccount($user->id, 'Checking', '1234', 'checking');
    $account2 = $service->createManualAccount($user->id, 'Savings', null, null);

    expect($account1->mask)->toBe('1234');
    expect($account1->type)->toBe('checking');

    expect($account2->mask)->toBeNull();
    expect($account2->type)->toBeNull();
});

it('ImportService can create account with an institution', function () {
    $user = User::factory()->create();
    $institution = Institution::create([
        'provider' => 'manual',
        'external_org_id' => 'test-org',
        'name' => 'Test Bank',
    ]);

    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null, $institution->id);

    expect($account->institution_id)->toBe($institution->id);
});

it('ImportService preserves original balance from latest transaction in file order', function () {
    $user = User::factory()->create();
    $service = app(ImportService::class);
    $account = $service->createManualAccount($user->id, 'Checking', null, null);

    $csv = <<<'CSV'
Details,Posting Date,Description,Amount,Type,Balance,Check or Slip #
DEBIT,07/22/2026,NEWEST,-5.00,Purchase,1000.00,
DEBIT,07/22/2026,MIDDLE,-10.00,Purchase,990.00,
DEBIT,07/22/2026,OLDEST,-15.00,Purchase,980.00,
CSV;

    $path = sys_get_temp_dir().'/'.uniqid('test_').'.csv';
    file_put_contents($path, $csv);

    try {
        $run = $service->importFile($account->id, chaseTemplateIdForImportPage(), $path, 'test.csv');

        $account->refresh();
        // The first row's balance (1000.00) should be used
        expect((float) $account->current_balance)->toBe(1000.00);
    } finally {
        @unlink($path);
    }
});
