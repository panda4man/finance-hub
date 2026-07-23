<?php

use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\ConnectionResource\Pages\EditConnection;
use App\Filament\Resources\ConnectionResource\Pages\ViewConnection;
use App\Filament\Resources\ConnectionResource\RelationManagers\AccountsRelationManager;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function makeConnectionForUser(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function makeAccountForConnection(Connection $connection, array $overrides = []): Account
{
    return Account::create(array_merge([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Test Account',
    ], $overrides));
}

it('displays account_type column in the AccountsRelationManager table', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = makeAccountForConnection($connection, [
        'account_type' => AccountType::Checking,
    ]);

    actingAs($user);

    Livewire::test(ViewConnection::class, ['record' => $connection->id])
        ->assertSuccessful();

    // Verify the account can be fetched and has the account_type set
    $fetched = Account::findOrFail($account->id);
    expect($fetched->account_type)->toBe(AccountType::Checking);
    expect($fetched->account_type->label())->toBe('Checking');
});

it('displays account_type with null fallback in the table', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = makeAccountForConnection($connection);
    // account_type will be null

    actingAs($user);

    Livewire::test(ViewConnection::class, ['record' => $connection->id])
        ->assertSuccessful();

    $fetched = Account::findOrFail($account->id);
    expect($fetched->account_type)->toBeNull();
});

it('displays account_type values as badges in the table', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    // Create accounts with different types
    makeAccountForConnection($connection, ['name' => 'Checking', 'account_type' => AccountType::Checking]);
    makeAccountForConnection($connection, ['name' => 'Savings', 'account_type' => AccountType::Savings]);
    makeAccountForConnection($connection, ['name' => 'Credit Card', 'account_type' => AccountType::CreditCard]);
    makeAccountForConnection($connection, ['name' => 'Other', 'account_type' => AccountType::Other]);

    actingAs($user);

    $accounts = Account::where('connection_id', $connection->id)->get();

    expect($accounts)->toHaveCount(4);
    expect($accounts[0]->account_type->label())->toBe('Checking');
    expect($accounts[1]->account_type->label())->toBe('Savings');
    expect($accounts[2]->account_type->label())->toBe('Credit card');
    expect($accounts[3]->account_type->label())->toBe('Other');
});

it('renders the institution logo column in the AccountsRelationManager table', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-'.Str::random(8),
        'name' => 'Test Bank',
        'logo_base64' => base64_encode('fake-logo-bytes'),
    ]);

    $account = makeAccountForConnection($connection, [
        'institution_id' => $institution->id,
    ]);

    actingAs($user);

    Livewire::test(AccountsRelationManager::class, [
        'ownerRecord' => $connection,
        'pageClass' => EditConnection::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet(
            'institution.logo_base64',
            'data:image/png;base64,'.$institution->logo_base64,
            record: $account,
        );
});

it('renders a null institution logo when the account has no institution', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = makeAccountForConnection($connection);

    actingAs($user);

    Livewire::test(AccountsRelationManager::class, [
        'ownerRecord' => $connection,
        'pageClass' => EditConnection::class,
    ])
        ->assertSuccessful()
        ->assertTableColumnStateSet('institution.logo_base64', null, record: $account);
});

it('updates account_type through the real Select field via the table EditAction', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = makeAccountForConnection($connection, [
        'account_type' => AccountType::Checking,
    ]);

    actingAs($user);

    // pageClass must be EditConnection, not ViewConnection: Filament's panel
    // default (hasReadOnlyRelationManagersOnResourceViewPagesByDefault) makes
    // every relation manager read-only — and its EditAction unusable — when
    // mounted under a ViewRecord page. EditConnection extends EditRecord
    // directly, so RelationManager::isReadOnly() is false there and the
    // edit action is actually reachable, exactly as it is on the real page.
    Livewire::test(AccountsRelationManager::class, [
        'ownerRecord' => $connection,
        'pageClass' => EditConnection::class,
    ])
        ->callTableAction('edit', $account, data: [
            'account_type' => AccountType::Savings->value,
        ])
        ->assertHasNoTableActionErrors();

    expect($account->fresh()->account_type)->toBe(AccountType::Savings);
});

it('has account_type in the fillable array and casts it', function () {
    $account = new Account;

    expect($account->getFillable())->toContain('account_type');

    // In Laravel 13, use the protected casts method via reflection or check by using the model
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $testAccount = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-test',
        'name' => 'Test',
        'account_type' => 'checking',
    ]);

    // The cast works if we get an enum back after setting a string
    expect($testAccount->account_type)->toBeInstanceOf(AccountType::class);
});

it('can create an account with account_type and retrieve it with correct enum', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-test-001',
        'name' => 'My Checking',
        'account_type' => 'checking', // Store as string, should be cast to enum
    ]);

    $refreshed = Account::findOrFail($account->id);

    expect($refreshed->account_type)->toBeInstanceOf(AccountType::class);
    expect($refreshed->account_type)->toBe(AccountType::Checking);
});

it('can update account_type via direct save', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = makeAccountForConnection($connection, [
        'account_type' => AccountType::Checking,
    ]);

    $account->update(['account_type' => AccountType::Savings]);

    $refreshed = Account::findOrFail($account->id);
    expect($refreshed->account_type)->toBe(AccountType::Savings);
});

it('preserves account_type when updating other fields', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForUser($user);

    $account = makeAccountForConnection($connection, [
        'account_type' => AccountType::CreditCard,
    ]);

    $account->update(['name' => 'Updated Name']);

    $refreshed = Account::findOrFail($account->id);
    expect($refreshed->account_type)->toBe(AccountType::CreditCard);
    expect($refreshed->name)->toBe('Updated Name');
});
