<?php

use App\Enums\AccountType;
use App\Mcp\Servers\FinanceInsightsServer;
use App\Mcp\Tools\ListAccounts;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

function laInstitution(string $name): Institution
{
    return Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-'.Str::random(8),
        'name' => $name,
    ]);
}

function laAccount(User $user, AccountType $type = AccountType::Checking, ?Institution $institution = null, array $attrs = []): Account
{
    return Account::factory()
        ->for(Connection::factory(['user_id' => $user->id]))
        ->ofType($type)
        ->create(array_merge(['institution_id' => $institution?->id], $attrs));
}

it('is registered as list_accounts with read-only, idempotent, closed-world annotations', function () {
    $tool = new ListAccounts;

    expect($tool->name())->toBe('list_accounts');
    expect($tool->annotations())->toEqualCanonicalizing([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('errors when unauthenticated', function () {
    FinanceInsightsServer::tool(ListAccounts::class, [])
        ->assertHasErrors(['Unauthenticated']);
});

it('lists only the user\'s accounts with institution and type', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $chase = laInstitution('JPMorgan Chase');
    laAccount($user, AccountType::Checking, $chase, ['name' => 'Mine']);
    laAccount($stranger, AccountType::Checking, $chase, ['name' => 'Not mine']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('count', 1)
            ->where('accounts.0.name', 'Mine')
            ->where('accounts.0.institution.name', 'JPMorgan Chase')
            ->where('accounts.0.type', 'checking')
            ->etc());
});

it('resolves an institution by case-insensitive name fragment', function () {
    $user = User::factory()->create();
    $chase = laInstitution('JPMorgan Chase');
    $wells = laInstitution('Wells Fargo');

    laAccount($user, AccountType::Checking, $chase, ['name' => 'Chase Checking']);
    laAccount($user, AccountType::Checking, $wells, ['name' => 'Wells Checking']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, ['institution' => 'chase'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('count', 1)
            ->where('accounts.0.name', 'Chase Checking')
            ->etc());
});

it('filters by institution uuid', function () {
    $user = User::factory()->create();
    $chase = laInstitution('JPMorgan Chase');
    $wells = laInstitution('Wells Fargo');

    laAccount($user, AccountType::Checking, $chase, ['name' => 'Chase Checking']);
    laAccount($user, AccountType::Checking, $wells, ['name' => 'Wells Checking']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, ['institution' => $chase->id])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('count', 1)
            ->where('accounts.0.name', 'Chase Checking')
            ->etc());
});

it('filters by account_types', function () {
    $user = User::factory()->create();
    laAccount($user, AccountType::Checking, null, ['name' => 'Checking']);
    laAccount($user, AccountType::CreditCard, null, ['name' => 'Credit card']);
    laAccount($user, AccountType::Savings, null, ['name' => 'Savings']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, ['account_types' => ['checking', 'credit_card']])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 2)->etc());
});

it('groups institutions with account counts', function () {
    $user = User::factory()->create();
    $chase = laInstitution('JPMorgan Chase');

    laAccount($user, AccountType::Checking, $chase, ['name' => 'Checking']);
    laAccount($user, AccountType::CreditCard, $chase, ['name' => 'Credit card']);
    laAccount($user, AccountType::Savings, null, ['name' => 'No institution']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('institutions.0.name', 'JPMorgan Chase')
            ->where('institutions.0.account_count', 2)
            ->etc());
});

it('counts transactions excluding removed and reports first/last dates', function () {
    $user = User::factory()->create();
    $account = laAccount($user);

    Transaction::factory()->for($account)->create(['date' => '2026-01-01']);
    Transaction::factory()->for($account)->create(['date' => '2026-06-01']);
    Transaction::factory()->for($account)->removed()->create(['date' => '2026-09-01']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('accounts.0.transaction_count', 2)
            ->where('accounts.0.first_transaction_date', '2026-01-01')
            ->where('accounts.0.last_transaction_date', '2026-06-01')
            ->etc());
});

it('returns an empty list for a user with no accounts', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->where('accounts', [])->etc());
});

it('rejects an unknown account type', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, ['account_types' => ['bogus']])
        ->assertHasErrors(['account_types.0']);
});

it('escapes LIKE wildcards in institution', function () {
    $user = User::factory()->create();
    laAccount($user, AccountType::Checking, laInstitution('First National Bank'), ['name' => 'Checking']);

    // Without escaping, '%' would act as a wildcard and match every institution name.
    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, ['institution' => '%'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
});

it('never returns another user\'s data', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    laAccount($stranger, AccountType::Checking, null, ['name' => 'Stranger account']);

    FinanceInsightsServer::actingAs($user)->tool(ListAccounts::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
});
