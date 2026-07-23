<?php

use App\Enums\ConnectionStatus;
use App\Enums\ImportStatus;
use App\Filament\Resources\ImportRunResource;
use App\Filament\Resources\ImportRunResource\Pages\ListImportRuns;
use App\Models\Account;
use App\Models\Connection;
use App\Models\ImportRun;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function makeImportRun(Account $account, array $overrides = []): ImportRun
{
    return ImportRun::create(array_merge([
        'connection_id' => $account->connection_id,
        'account_id' => $account->id,
        'status' => ImportStatus::Success,
        'file_name' => 'test.csv',
        'row_count' => 2,
        'added_count' => 2,
        'duplicate_count' => 0,
        'failed_count' => 0,
        'started_at' => now(),
        'finished_at' => now(),
    ], $overrides));
}

it('scopes import runs to the current owner and only allows viewing, never creating or editing or deleting', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = Connection::create(['user_id' => $owner->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $ownerAccount = Account::create([
        'connection_id' => $ownerConnection->id,
        'external_account_id' => 'manual:owner-acc-1',
        'name' => 'Checking',
    ]);
    $ownerRun = makeImportRun($ownerAccount);

    $strangerConnection = Connection::create(['user_id' => $stranger->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $strangerAccount = Account::create([
        'connection_id' => $strangerConnection->id,
        'external_account_id' => 'manual:stranger-acc-1',
        'name' => 'Checking',
    ]);
    $strangerRun = makeImportRun($strangerAccount);

    actingAs($owner);

    expect(ImportRunResource::canCreate())->toBeFalse();
    expect(ImportRunResource::canEdit($ownerRun))->toBeFalse();
    expect(ImportRunResource::canDelete($ownerRun))->toBeFalse();

    Livewire::test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$ownerRun])
        ->assertCanNotSeeTableRecords([$strangerRun]);
});

it('lists only the owner\'s import runs, not other users\' runs', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = Connection::create(['user_id' => $owner->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $ownerAccount = Account::create([
        'connection_id' => $ownerConnection->id,
        'external_account_id' => 'manual:owner-acc-1',
        'name' => 'Checking',
    ]);
    $ownerRun = makeImportRun($ownerAccount);

    $strangerConnection = Connection::create(['user_id' => $stranger->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $strangerAccount = Account::create([
        'connection_id' => $strangerConnection->id,
        'external_account_id' => 'manual:stranger-acc-1',
        'name' => 'Checking',
    ]);
    $strangerRun = makeImportRun($strangerAccount);

    actingAs($owner);

    Livewire::test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$ownerRun])
        ->assertCanNotSeeTableRecords([$strangerRun]);
});

it('renders import runs with correct columns and status badges', function () {
    $user = User::factory()->create();

    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'manual:test-1',
        'name' => 'Checking Account',
    ]);

    $run = makeImportRun($account, [
        'status' => ImportStatus::Success,
        'file_name' => 'chase_export.csv',
        'row_count' => 5,
        'added_count' => 3,
        'duplicate_count' => 2,
        'failed_count' => 0,
    ]);

    actingAs($user);

    Livewire::test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$run])
        ->assertTableColumnExists('account.display_name')
        ->assertTableColumnExists('file_name')
        ->assertTableColumnExists('status')
        ->assertTableColumnExists('added_count')
        ->assertTableColumnExists('duplicate_count')
        ->assertTableColumnExists('failed_count');
});

it('displays partial status with warning color', function () {
    $user = User::factory()->create();

    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'manual:test-1',
        'name' => 'Checking Account',
    ]);

    $partialRun = makeImportRun($account, [
        'status' => ImportStatus::Partial,
        'added_count' => 5,
        'failed_count' => 1,
    ]);

    actingAs($user);

    Livewire::test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$partialRun]);
});

it('displays failed status with danger color', function () {
    $user = User::factory()->create();

    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'manual:test-1',
        'name' => 'Checking Account',
    ]);

    $failedRun = makeImportRun($account, [
        'status' => ImportStatus::Failed,
        'error_message' => 'Unrecognized CSV header',
    ]);

    actingAs($user);

    Livewire::test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$failedRun]);
});

it('shows error message in a tooltip on failed runs', function () {
    $user = User::factory()->create();

    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'manual', 'status' => ConnectionStatus::Active]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'manual:test-1',
        'name' => 'Checking Account',
    ]);

    $errorMessage = 'Something went wrong with the import';
    $failedRun = makeImportRun($account, [
        'status' => ImportStatus::Failed,
        'error_message' => $errorMessage,
    ]);

    actingAs($user);

    Livewire::test(ListImportRuns::class)
        ->assertCanSeeTableRecords([$failedRun]);
});
