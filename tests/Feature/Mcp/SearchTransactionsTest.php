<?php

use App\Enums\AccountType;
use App\Mcp\Servers\FinanceInsightsServer;
use App\Mcp\Tools\SearchTransactions;
use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function stInstitution(string $name): Institution
{
    return Institution::create([
        'provider' => 'simplefin',
        'external_org_id' => 'org-'.Str::random(8),
        'name' => $name,
    ]);
}

function stAccount(User $user, AccountType $type = AccountType::Checking, ?Institution $institution = null): Account
{
    return Account::factory()
        ->for(Connection::factory(['user_id' => $user->id]))
        ->ofType($type)
        ->create(['institution_id' => $institution?->id]);
}

function stTxn(Account $account, string $amount, string $date, array $attrs = []): Transaction
{
    return Transaction::factory()->for($account)->create(array_merge([
        'amount' => $amount,
        'date' => $date,
        'name' => 'Unnamed',
    ], $attrs));
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-28'));
});

it('is registered as search_transactions with read-only, idempotent, closed-world annotations', function () {
    $tool = new SearchTransactions;

    expect($tool->name())->toBe('search_transactions');
    expect($tool->annotations())->toEqualCanonicalizing([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('errors when unauthenticated', function () {
    FinanceInsightsServer::tool(SearchTransactions::class, [])
        ->assertHasErrors(['Unauthenticated']);
});

it('returns the user\'s transactions newest first by default', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    stTxn($account, '10.00', '2026-09-01', ['name' => 'First']);
    stTxn($account, '20.00', '2026-09-05', ['name' => 'Second']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('transactions.0.name', 'Second')
            ->where('transactions.1.name', 'First')
            ->etc());
});

it('answers "when was the last time I purchased at Target"', function () {
    $user = User::factory()->create();
    $account = stAccount($user);
    $other = stAccount($user);

    stTxn($account, '40.00', '2026-01-05', ['merchant_name' => 'Target', 'name' => 'Target run 1']);
    stTxn($account, '55.00', '2026-08-10', ['merchant_name' => 'Target', 'name' => 'Target run 2']);
    stTxn($account, '-10.00', '2026-09-01', ['merchant_name' => 'Target', 'name' => 'Target refund']);
    stTxn($other, '30.00', '2026-08-15', ['merchant_name' => 'Walmart', 'name' => 'Walmart run']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [
        'merchant' => 'target',
        'direction' => 'outflow',
        'sort' => 'date',
        'order' => 'desc',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total_matching', 2)
            ->where('truncated', true)
            ->where('transactions.0.date', '2026-08-10')
            ->where('transactions.0.direction', 'outflow')
            ->etc());
});

it('answers "largest credit in the last year"', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    stTxn($account, '-50.00', '2026-03-01', ['name' => 'Small refund']);
    stTxn($account, '-1200.00', '2025-12-01', ['name' => 'Big credit']);
    stTxn($account, '-5000.00', '2025-06-01', ['name' => 'Too old']);
    stTxn($account, '9999.00', '2026-01-01', ['name' => 'Spending']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [
        'direction' => 'inflow',
        'date_from' => '2025-09-28',
        'sort' => 'magnitude',
        'order' => 'desc',
        'limit' => 1,
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('transactions.0.amount', '-1200.00')
            ->where('transactions.0.direction', 'inflow')
            ->etc());
});

it('sorts by signed amount ascending', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    stTxn($account, '-30.00', '2026-01-01', ['name' => 'A']);
    stTxn($account, '10.00', '2026-01-02', ['name' => 'B']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [
        'sort' => 'amount',
        'order' => 'asc',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('transactions.0.name', 'A')
            ->where('transactions.1.name', 'B')
            ->etc());
});

it('sorts by magnitude ascending', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    stTxn($account, '-30.00', '2026-01-01', ['name' => 'Big']);
    stTxn($account, '10.00', '2026-01-02', ['name' => 'Small']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [
        'sort' => 'magnitude',
        'order' => 'asc',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('transactions.0.name', 'Small')
            ->where('transactions.1.name', 'Big')
            ->etc());
});

it('excludes pending and hidden by default', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    Transaction::factory()->for($account)->pending()->create(['name' => 'Pending']);
    Transaction::factory()->for($account)->hidden()->create(['name' => 'Hidden']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 0)->etc());
});

it('includes pending when include_pending is true', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    Transaction::factory()->for($account)->pending()->create(['name' => 'Pending']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['include_pending' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 1)->etc());
});

it('includes hidden when include_hidden is true', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    Transaction::factory()->for($account)->hidden()->create(['name' => 'Hidden']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['include_hidden' => true])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 1)->etc());
});

it('filters by account_types and institution name', function () {
    $user = User::factory()->create();
    $chase = stInstitution('JPMorgan Chase');
    $checking = stAccount($user, AccountType::Checking, $chase);
    $creditCard = stAccount($user, AccountType::CreditCard, $chase);
    $savings = stAccount($user, AccountType::Savings, $chase);

    stTxn($checking, '10.00', '2026-01-01', ['name' => 'Checking txn']);
    stTxn($creditCard, '20.00', '2026-01-02', ['name' => 'CC txn']);
    stTxn($savings, '30.00', '2026-01-03', ['name' => 'Savings txn']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [
        'institution' => 'chase',
        'account_types' => ['checking', 'credit_card'],
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 2)->etc());
});

it('filters by categories slug', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    $groceries = Category::create(['slug' => 'groceries', 'name' => 'Groceries', 'kind' => 'custom', 'is_active' => true]);
    $dining = Category::create(['slug' => 'dining', 'name' => 'Dining', 'kind' => 'custom', 'is_active' => true]);

    stTxn($account, '10.00', '2026-01-01', ['name' => 'Groceries', 'category_id' => $groceries->id]);
    stTxn($account, '20.00', '2026-01-02', ['name' => 'Dining', 'category_id' => $dining->id]);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['categories' => ['groceries']])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 1)->etc());
});

it('reports matching_net_amount across all matches, not just the returned page', function () {
    $user = User::factory()->create();
    $account = stAccount($user);

    stTxn($account, '10.00', '2026-01-01', ['name' => 'A']);
    stTxn($account, '20.00', '2026-01-02', ['name' => 'B']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['limit' => 1])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('matching_net_amount', '30.00')
            ->where('returned', 1)
            ->etc());
});

it('returns an empty list with total_matching 0 when nothing matches', function () {
    $user = User::factory()->create();
    stAccount($user);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['merchant' => 'nonexistent'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total_matching', 0)
            ->where('transactions', [])
            ->etc());
});

it('never returns another user\'s data', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();

    $strangerAccount = stAccount($stranger);
    stTxn($strangerAccount, '10.00', '2026-01-01', ['merchant_name' => 'Target', 'name' => 'Target run']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['merchant' => 'target'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('total_matching', 0)->etc());
});

it('rejects an amount_min above the cap instead of overflowing', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['amount_min' => 1.0e20])
        ->assertHasErrors(['amount min']);
});

it('rejects an amount_max above the cap instead of overflowing', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['amount_max' => 1.0e20])
        ->assertHasErrors(['amount max']);
});

it('rejects negative amount_min', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['amount_min' => -5])
        ->assertHasErrors(['amount min']);
});

it('rejects a malformed date_from', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['date_from' => '2026/01/01'])
        ->assertHasErrors(['date from']);
});

it('rejects date_to before date_from', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [
        'date_from' => '2026-09-27',
        'date_to' => '2026-09-26',
    ])->assertHasErrors(['date to']);
});

it('accepts date_to without date_from', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['date_to' => '2026-09-26'])
        ->assertOk();
});

it('rejects an unknown direction', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['direction' => 'sideways'])
        ->assertHasErrors(['direction']);
});

it('rejects an unknown account type', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['account_types' => ['bogus']])
        ->assertHasErrors(['account_types.0']);
});

it('rejects a non-uuid account id', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['account_ids' => ['not-a-uuid']])
        ->assertHasErrors(['account_ids.0']);
});

it('rejects limit 0 and 101', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['limit' => 0])
        ->assertHasErrors(['limit']);

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['limit' => 101])
        ->assertHasErrors(['limit']);
});

it('rejects an unknown sort', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['sort' => 'bogus'])
        ->assertHasErrors(['sort']);
});

it('accepts the maximum amount 999999999999.99', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, ['amount_min' => 999999999999.99])
        ->assertOk();
});

it('echoes applied_filters including defaults', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SearchTransactions::class, [])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('applied_filters.direction', 'any')
            ->where('applied_filters.sort', 'date')
            ->where('applied_filters.order', 'desc')
            ->where('applied_filters.limit', 25)
            ->where('applied_filters.include_pending', false)
            ->etc());
});
