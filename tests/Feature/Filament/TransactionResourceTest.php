<?php

use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use Filament\Tables\Columns\IconColumn;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource query
// scoping/table behavior, not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function makeConnectionForOwner(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function makeAccountFor(Connection $connection): Account
{
    return Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
    ]);
}

function makeTransactionRow(Account $account, Connection $connection, array $overrides = []): Transaction
{
    return Transaction::create(array_merge([
        'account_id' => $account->id,
        'connection_id' => $connection->id,
        'external_transaction_id' => 'txn-'.Str::random(12),
        'amount' => 42.50,
        'date' => now()->toDateString(),
        'name' => 'Whole Foods Market',
        'raw_payload' => [],
    ], $overrides));
}

it('drives the effective-category badge from userCategory when present, else category', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);
    $account = makeAccountFor($connection);

    $sourceCategory = Category::create(['slug' => 'groceries', 'name' => 'Groceries', 'kind' => 'custom', 'is_active' => true]);
    $overrideCategory = Category::create(['slug' => 'dining', 'name' => 'Dining', 'kind' => 'custom', 'is_active' => true]);

    $transaction = makeTransactionRow($account, $connection, [
        'category_id' => $sourceCategory->id,
        'user_category_id' => $overrideCategory->id,
    ]);

    actingAs($user);

    $record = TransactionResource::getEloquentQuery()->findOrFail($transaction->id);

    expect(($record->userCategory ?? $record->category)?->name)->toBe('Dining');

    // Simulate what an inline SelectColumn edit does: clear the override,
    // then reload the same row the way a single-row Livewire refresh would —
    // via the resource's own scoped query, not the stale in-memory model.
    $transaction->update(['user_category_id' => null]);
    $refreshed = TransactionResource::getEloquentQuery()->findOrFail($transaction->id);

    expect(($refreshed->userCategory ?? $refreshed->category)?->name)->toBe('Groceries');
});

it('scopes transactions to the current owner and eager-loads account/category/userCategory', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = makeConnectionForOwner($owner);
    $ownerAccount = makeAccountFor($ownerConnection);
    makeTransactionRow($ownerAccount, $ownerConnection, ['name' => 'Mine']);

    $strangerConnection = makeConnectionForOwner($stranger);
    $strangerAccount = makeAccountFor($strangerConnection);
    makeTransactionRow($strangerAccount, $strangerConnection, ['name' => 'Not mine']);

    actingAs($owner);

    $results = TransactionResource::getEloquentQuery()->get();

    expect($results)->toHaveCount(1);
    expect($results->first()->name)->toBe('Mine');
    expect($results->first()->relationLoaded('account'))->toBeTrue();
    expect($results->first()->relationLoaded('category'))->toBeTrue();
    expect($results->first()->relationLoaded('userCategory'))->toBeTrue();
});

it('filters transactions by effective category via COALESCE, not the query alias', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);
    $account = makeAccountFor($connection);

    $matching = Category::create(['slug' => 'travel', 'name' => 'Travel', 'kind' => 'custom', 'is_active' => true]);
    $other = Category::create(['slug' => 'utilities', 'name' => 'Utilities', 'kind' => 'custom', 'is_active' => true]);

    // Matches via source-derived category_id (no override).
    $viaCategoryId = makeTransactionRow($account, $connection, ['name' => 'Flight', 'category_id' => $matching->id]);
    // Matches via user override even though the source category differs.
    $viaOverride = makeTransactionRow($account, $connection, ['name' => 'Hotel', 'category_id' => $other->id, 'user_category_id' => $matching->id]);
    // Should not match.
    $nonMatching = makeTransactionRow($account, $connection, ['name' => 'Electric bill', 'category_id' => $other->id]);

    actingAs($user);

    Livewire::test(ListTransactions::class)
        ->filterTable('category', ['category_id' => $matching->id])
        ->assertCanSeeTableRecords([$viaCategoryId, $viaOverride])
        ->assertCanNotSeeTableRecords([$nonMatching]);
});

it('renders account type icon column correctly with account_type set', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);
    $account = makeAccountFor($connection);
    $account->update(['account_type' => AccountType::CreditCard]);

    $transaction = makeTransactionRow($account, $connection);

    actingAs($user);

    // Drives the real IconColumn::icon() closure defined in
    // TransactionResource::table(), not a re-derivation of the same
    // expression against the model directly.
    Livewire::test(ListTransactions::class)
        ->assertTableColumnExists(
            'account.account_type',
            fn (IconColumn $column): bool => $column->getIcon($column->getState()) === AccountType::CreditCard->icon(),
            record: $transaction,
        );
});

it('renders account type icon column with fallback to Other when account_type is null', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);
    $account = makeAccountFor($connection);
    // Leave account_type as null

    $transaction = makeTransactionRow($account, $connection);

    actingAs($user);

    Livewire::test(ListTransactions::class)
        ->assertTableColumnExists(
            'account.account_type',
            fn (IconColumn $column): bool => $column->getIcon($column->getState()) === AccountType::Other->icon(),
            record: $transaction,
        );
});

it('renders bank logo image column correctly with logo_base64 present', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);

    $institution = Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-123',
        'name' => 'Chase Bank',
        'logo_base64' => base64_encode('fake-png-data'),
    ]);

    $account = Account::create([
        'connection_id' => $connection->id,
        'institution_id' => $institution->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
        'account_type' => AccountType::Checking,
    ]);

    $transaction = makeTransactionRow($account, $connection);

    actingAs($user);

    // Drives the real ImageColumn::getStateUsing() closure defined in
    // TransactionResource::table().
    Livewire::test(ListTransactions::class)
        ->assertTableColumnStateSet(
            'account.institution.logo_base64',
            'data:image/png;base64,'.base64_encode('fake-png-data'),
            $transaction,
        );
});

it('renders bank logo image column with null when no institution or no logo_base64', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);
    $account = makeAccountFor($connection);
    // No institution attached

    $transaction = makeTransactionRow($account, $connection);

    actingAs($user);

    Livewire::test(ListTransactions::class)
        ->assertTableColumnStateSet('account.institution.logo_base64', null, $transaction);
});

it('eager-loads account_type and institution for the table columns', function () {
    $user = User::factory()->create();
    $connection = makeConnectionForOwner($user);
    $account = makeAccountFor($connection);

    makeTransactionRow($account, $connection);

    actingAs($user);

    $record = TransactionResource::getEloquentQuery()->first();

    // Verify account is loaded with the right columns including account_type and institution_id
    expect($record->relationLoaded('account'))->toBeTrue();
    expect($record->account->relationLoaded('institution'))->toBeTrue();
});
