<?php

use App\Enums\AccountType;
use App\Mcp\Servers\FinanceInsightsServer;
use App\Mcp\Tools\FindRepeatPurchases;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

function frpInstitution(string $name): Institution
{
    return Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-'.Str::random(8),
        'name' => $name,
    ]);
}

function frpAccount(User $user, AccountType $type = AccountType::Checking, ?Institution $institution = null): Account
{
    return Account::factory()
        ->for(Connection::factory(['user_id' => $user->id]))
        ->ofType($type)
        ->create(['institution_id' => $institution?->id]);
}

function frpTxn(Account $account, string $amount, string $date, array $attrs = []): Transaction
{
    return Transaction::factory()->for($account)->create(array_merge([
        'amount' => $amount,
        'date' => $date,
        'name' => 'Unnamed',
    ], $attrs));
}

it('is registered as find_repeat_purchases with read-only, idempotent, closed-world annotations', function () {
    $tool = new FindRepeatPurchases;

    expect($tool->name())->toBe('find_repeat_purchases');
    expect($tool->annotations())->toEqualCanonicalizing([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('errors when unauthenticated', function () {
    FinanceInsightsServer::tool(FindRepeatPurchases::class, [])
        ->assertHasErrors(['Unauthenticated']);
});

it('answers "repeat purchases at Chase, checking/credit card only, in a date and amount range"', function () {
    $user = User::factory()->create();
    $chase = frpInstitution('JPMorgan Chase');
    $wells = frpInstitution('Wells Fargo');

    $chaseChecking = frpAccount($user, AccountType::Checking, $chase);
    $chaseCreditCard = frpAccount($user, AccountType::CreditCard, $chase);
    $chaseSavings = frpAccount($user, AccountType::Savings, $chase);
    $wellsChecking = frpAccount($user, AccountType::Checking, $wells);

    // 3x Starbucks on Chase checking, inside the range.
    frpTxn($chaseChecking, '5.25', '2026-06-01', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);
    frpTxn($chaseChecking, '5.25', '2026-06-08', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);
    frpTxn($chaseChecking, '5.25', '2026-06-15', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);

    // 2x Amazon on Chase credit card, inside the range.
    frpTxn($chaseCreditCard, '40.00', '2026-06-02', ['name' => 'Amazon', 'merchant_name' => 'Amazon']);
    frpTxn($chaseCreditCard, '40.00', '2026-06-09', ['name' => 'Amazon', 'merchant_name' => 'Amazon']);

    // 2x transfer on Chase savings — excluded by account_types.
    frpTxn($chaseSavings, '100.00', '2026-06-03', ['name' => 'Transfer', 'merchant_name' => 'Transfer']);
    frpTxn($chaseSavings, '100.00', '2026-06-10', ['name' => 'Transfer', 'merchant_name' => 'Transfer']);

    // 2x Starbucks on Wells checking — excluded by institution.
    frpTxn($wellsChecking, '5.25', '2026-06-04', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);
    frpTxn($wellsChecking, '5.25', '2026-06-11', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);

    // Starbucks at 500 — excluded by amount_max.
    frpTxn($chaseChecking, '500.00', '2026-06-05', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);

    // Starbucks outside the date range.
    frpTxn($chaseChecking, '5.25', '2026-01-01', ['name' => 'Starbucks', 'merchant_name' => 'Starbucks']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, [
        'institution' => 'chase',
        'account_types' => ['checking', 'credit_card'],
        'direction' => 'outflow',
        'date_from' => '2026-06-01',
        'date_to' => '2026-06-30',
        'amount_max' => 100,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('group_count', 2)
            ->where('groups.0.merchant', 'starbucks')
            ->where('groups.0.count', 3)
            ->where('groups.0.total', '15.75')
            ->where('groups.0.average', '5.25')
            ->where('groups.1.merchant', 'amazon')
            ->where('groups.1.count', 2)
            ->etc());
});

it('excludes merchants seen fewer than min_occurrences', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '10.00', '2026-01-01', ['name' => 'Once', 'merchant_name' => 'Once']);
    frpTxn($account, '10.00', '2026-01-01', ['name' => 'Twice', 'merchant_name' => 'Twice']);
    frpTxn($account, '10.00', '2026-01-08', ['name' => 'Twice', 'merchant_name' => 'Twice']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('group_count', 1)
            ->where('groups.0.merchant', 'twice')
            ->etc());
});

it('defaults direction to outflow', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '-10.00', '2026-01-01', ['name' => 'Refund', 'merchant_name' => 'Refund']);
    frpTxn($account, '-10.00', '2026-01-08', ['name' => 'Refund', 'merchant_name' => 'Refund']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('group_count', 0)
            ->where('applied_filters.direction', 'outflow')
            ->etc());
});

it('includes inflows when direction is inflow', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '-10.00', '2026-01-01', ['name' => 'Refund', 'merchant_name' => 'Refund']);
    frpTxn($account, '-10.00', '2026-01-08', ['name' => 'Refund', 'merchant_name' => 'Refund']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['direction' => 'inflow'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('group_count', 1)->etc());
});

it('limits sample_transactions to sample_size, newest first', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '10.00', '2026-01-01', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);
    frpTxn($account, '10.00', '2026-01-08', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);
    frpTxn($account, '10.00', '2026-01-15', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['sample_size' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('groups.0.sample_transactions', 2)
            ->where('groups.0.sample_transactions.0.date', '2026-01-15')
            ->etc());
});

it('sample_size 0 returns no samples', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '10.00', '2026-01-01', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);
    frpTxn($account, '10.00', '2026-01-08', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['sample_size' => 0])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('groups.0.sample_transactions', [])->etc());
});

it('sorts by total', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '5.00', '2026-01-01', ['name' => 'Small', 'merchant_name' => 'Small']);
    frpTxn($account, '5.00', '2026-01-08', ['name' => 'Small', 'merchant_name' => 'Small']);
    frpTxn($account, '50.00', '2026-01-01', ['name' => 'Big', 'merchant_name' => 'Big']);
    frpTxn($account, '50.00', '2026-01-08', ['name' => 'Big', 'merchant_name' => 'Big']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['sort' => 'total'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('groups.0.merchant', 'big')->etc());
});

it('sorts by last_date', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '5.00', '2026-01-01', ['name' => 'Old', 'merchant_name' => 'Old']);
    frpTxn($account, '5.00', '2026-01-02', ['name' => 'Old', 'merchant_name' => 'Old']);
    frpTxn($account, '5.00', '2026-02-01', ['name' => 'Recent', 'merchant_name' => 'Recent']);
    frpTxn($account, '5.00', '2026-02-15', ['name' => 'Recent', 'merchant_name' => 'Recent']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['sort' => 'last_date'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('groups.0.merchant', 'recent')->etc());
});

it('flags truncated when more groups than limit', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    foreach (['A', 'B', 'C'] as $name) {
        frpTxn($account, '5.00', '2026-01-01', ['name' => $name, 'merchant_name' => $name]);
        frpTxn($account, '5.00', '2026-01-08', ['name' => $name, 'merchant_name' => $name]);
    }

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('group_count', 2)
            ->where('truncated', true)
            ->etc());
});

it('returns no groups when nothing repeats', function () {
    $user = User::factory()->create();
    $account = frpAccount($user);

    frpTxn($account, '5.00', '2026-01-01', ['name' => 'Once', 'merchant_name' => 'Once']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('group_count', 0)->where('groups', [])->etc());
});

it('never returns another user\'s data', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $strangerAccount = frpAccount($stranger);

    frpTxn($strangerAccount, '5.00', '2026-01-01', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);
    frpTxn($strangerAccount, '5.00', '2026-01-08', ['name' => 'Coffee', 'merchant_name' => 'Coffee']);

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('group_count', 0)->etc());
});

it('rejects min_occurrences 1', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['min_occurrences' => 1])
        ->assertHasErrors(['min occurrences']);
});

it('rejects sample_size 11', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['sample_size' => 11])
        ->assertHasErrors(['sample size']);
});

it('rejects amount_min above the cap', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['amount_min' => 1.0e20])
        ->assertHasErrors(['amount min']);
});

it('rejects an unknown sort', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(FindRepeatPurchases::class, ['sort' => 'bogus'])
        ->assertHasErrors(['sort']);
});
