<?php

use App\Enums\AccountType;
use App\Mcp\Servers\FinanceInsightsServer;
use App\Mcp\Tools\SearchMerchants;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;

function smAccount(User $user, AccountType $type = AccountType::Checking): Account
{
    return Account::factory()->for(Connection::factory(['user_id' => $user->id]))->ofType($type)->create();
}

function smTxn(Account $account, string $amount, string $date, array $attrs = []): Transaction
{
    return Transaction::factory()->for($account)->create(array_merge([
        'amount' => $amount,
        'date' => $date,
        'name' => 'Unnamed',
    ], $attrs));
}

it('is registered as search_merchants with read-only, idempotent, closed-world annotations', function () {
    $tool = new SearchMerchants;

    expect($tool->name())->toBe('search_merchants');
    expect($tool->annotations())->toEqualCanonicalizing([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('errors when unauthenticated', function () {
    FinanceInsightsServer::tool(SearchMerchants::class, ['query' => 'target'])
        ->assertHasErrors(['Unauthenticated']);
});

it('finds "Duke Energy" when the descriptor is "DUKE-ENERGY PAYMENT 1234"', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    smTxn($account, '85.00', '2026-01-01', ['name' => 'DUKE-ENERGY PAYMENT 1234', 'merchant_name' => null]);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'Duke Energy'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 1)->etc());
});

it('does not match when only one of the words appears', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    smTxn($account, '10.00', '2026-01-01', ['name' => 'Duke Hospital Copay']);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'Duke Energy'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
});

it('groups by merchant_name, falling back to the raw name when merchant_name is null or empty', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    // merchant_name populated: groups by the merchant key regardless of the raw name.
    smTxn($account, '10.00', '2026-01-01', ['name' => 'Target run A', 'merchant_name' => 'Target']);
    smTxn($account, '10.00', '2026-01-02', ['name' => 'Target run B', 'merchant_name' => 'Target']);
    // merchant_name blank/null: falls back to the raw name, so identical names group together.
    smTxn($account, '10.00', '2026-01-03', ['name' => 'Target Store 123', 'merchant_name' => '']);
    smTxn($account, '10.00', '2026-01-04', ['name' => 'Target Store 123', 'merchant_name' => null]);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'target'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('count', 2)
            ->etc());
});

it('groups case and whitespace variants under one merchant key', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    smTxn($account, '10.00', '2026-01-01', ['name' => 'T1', 'merchant_name' => 'Target']);
    smTxn($account, '10.00', '2026-01-02', ['name' => 'T2', 'merchant_name' => ' TARGET ']);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'target'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('count', 1)
            ->where('merchants.0.merchant', 'target')
            ->where('merchants.0.transaction_count', 2)
            ->etc());
});

it('orders by transaction count and reports totals, first/last dates and name variants', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    smTxn($account, '10.00', '2026-01-01', ['name' => 'A', 'merchant_name' => 'Aldi']);
    smTxn($account, '10.00', '2026-02-01', ['name' => 'A2', 'merchant_name' => 'Aldi']);
    smTxn($account, '10.00', '2026-03-01', ['name' => 'A3', 'merchant_name' => 'Aldi']);
    smTxn($account, '10.00', '2026-01-15', ['name' => 'Z', 'merchant_name' => 'Aldi Express']);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'aldi'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('merchants.0.merchant', 'aldi')
            ->where('merchants.0.transaction_count', 3)
            ->where('merchants.0.total_amount', '30.00')
            ->where('merchants.0.first_date', '2026-01-01')
            ->where('merchants.0.last_date', '2026-03-01')
            ->etc());
});

it('the returned merchant key round-trips into search_transactions', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    smTxn($account, '10.00', '2026-01-01', ['name' => 'DUKE-ENERGY PAYMENT', 'merchant_name' => null]);
    smTxn($account, '20.00', '2026-02-01', ['name' => 'DUKE-ENERGY PAYMENT', 'merchant_name' => null]);

    $key = null;

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'duke energy'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('merchants.0.merchant', function ($actual) use (&$key) {
                $key = $actual;

                return true;
            })
            ->etc());

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['merchant' => $key])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 2)->etc());
});

it('honours direction and date filters', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    smTxn($account, '10.00', '2026-01-01', ['name' => 'Target out', 'merchant_name' => 'Target']);
    smTxn($account, '-10.00', '2026-01-02', ['name' => 'Target refund', 'merchant_name' => 'Target']);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'target', 'direction' => 'inflow'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('merchants.0.transaction_count', 1)
            ->where('merchants.0.total_amount', '-10.00')
            ->etc());
});

it('excludes hidden and pending by default', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    Transaction::factory()->for($account)->hidden()->create(['name' => 'Target', 'merchant_name' => 'Target']);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'target'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
});

it('caps results at limit and flags truncated', function () {
    $user = User::factory()->create();
    $account = smAccount($user);

    foreach (range(1, 3) as $i) {
        smTxn($account, '10.00', "2026-01-0{$i}", ['name' => "Merchant {$i} store", 'merchant_name' => "Merchant {$i}"]);
    }

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'merchant', 'limit' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('count', 2)
            ->where('truncated', true)
            ->etc());
});

it('returns empty merchants for no match', function () {
    $user = User::factory()->create();
    smAccount($user);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'nonexistent'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->where('merchants', [])->etc());
});

it('never returns another user\'s data', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $strangerAccount = smAccount($stranger);
    smTxn($strangerAccount, '10.00', '2026-01-01', ['name' => 'Target', 'merchant_name' => 'Target']);

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'target'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('count', 0)->etc());
});

it('requires query', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, [])
        ->assertHasErrors(['query']);
});

it('rejects a 1-char query', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'a'])
        ->assertHasErrors(['query']);
});

it('rejects a query with only punctuation', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => '!!'])
        ->assertHasErrors(['query']);
});

it('rejects amount_min above the cap', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchMerchants::class, ['query' => 'target', 'amount_min' => 1.0e20])
        ->assertHasErrors(['amount min']);
});
