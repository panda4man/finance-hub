<?php

use App\Mcp\Servers\FinanceInsightsServer;
use App\Mcp\Tools\SpendingTrend;
use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;

function stdAccount(User $user): Account
{
    return Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
}

function stdTxn(Account $account, string $amount, string $date, array $attrs = []): Transaction
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

it('is registered as spending_trend with read-only, idempotent, closed-world annotations', function () {
    $tool = new SpendingTrend;

    expect($tool->name())->toBe('spending_trend');
    expect($tool->annotations())->toEqualCanonicalizing([
        'readOnlyHint' => true,
        'idempotentHint' => true,
        'openWorldHint' => false,
    ]);
});

it('errors when unauthenticated', function () {
    FinanceInsightsServer::tool(SpendingTrend::class, ['merchant' => 'duke energy'])
        ->assertHasErrors(['Unauthenticated']);
});

it('answers "trend of my Duke Energy bill over the last few years" monthly', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);

    stdTxn($account, '80.00', '2024-01-15', ['name' => 'Duke Energy', 'merchant_name' => 'Duke Energy']);
    stdTxn($account, '75.00', '2024-03-05', ['name' => 'Duke Energy', 'merchant_name' => 'Duke Energy']);
    stdTxn($account, '90.00', '2024-03-20', ['name' => 'Duke Energy', 'merchant_name' => 'Duke Energy']);
    stdTxn($account, '110.00', '2026-09-10', ['name' => 'Duke Energy', 'merchant_name' => 'Duke Energy']);
    stdTxn($account, '50.00', '2026-05-01', ['name' => 'Walmart', 'merchant_name' => 'Walmart']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'duke energy',
        'granularity' => 'month',
        'date_from' => '2024-01-01',
        'date_to' => '2026-09-28',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('series', 33)
            ->where('series.1.label', '2024-02')
            ->where('series.1.total', '0.00')
            ->where('series.1.count', 0)
            ->where('series.2.label', '2024-03')
            ->where('series.2.total', '165.00')
            ->where('series.2.count', 2)
            ->where('summary.total', '355.00')
            ->etc());
});

it('gap-fills missing periods with zero', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);

    stdTxn($account, '10.00', '2026-01-15', ['name' => 'Gym', 'merchant_name' => 'Gym']);
    stdTxn($account, '10.00', '2026-03-15', ['name' => 'Gym', 'merchant_name' => 'Gym']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'month',
        'date_from' => '2026-01-01',
        'date_to' => '2026-03-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('series', 3)
            ->where('series.1.total', '0.00')
            ->etc());
});

it('aggregates by quarter with YYYY-Qn labels', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);

    stdTxn($account, '10.00', '2026-01-15', ['name' => 'Gym', 'merchant_name' => 'Gym']);
    stdTxn($account, '20.00', '2026-02-15', ['name' => 'Gym', 'merchant_name' => 'Gym']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'quarter',
        'date_from' => '2026-01-01',
        'date_to' => '2026-03-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('series', 1)
            ->where('series.0.label', '2026-Q1')
            ->where('series.0.total', '30.00')
            ->etc());
});

it('aggregates by year', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);

    stdTxn($account, '10.00', '2025-06-15', ['name' => 'Gym', 'merchant_name' => 'Gym']);
    stdTxn($account, '20.00', '2026-06-15', ['name' => 'Gym', 'merchant_name' => 'Gym']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'year',
        'date_from' => '2025-01-01',
        'date_to' => '2026-12-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('series', 2)
            ->where('series.0.label', '2025')
            ->where('series.1.label', '2026')
            ->etc());
});

it('defaults to the last 3 years and outflow', function () {
    $user = User::factory()->create();
    stdAccount($user);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, ['merchant' => 'gym'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('applied_filters.date_from', '2023-09-01')
            ->where('applied_filters.direction', 'outflow')
            ->where('granularity', 'month')
            ->etc());
});

it('trends by category slug', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);
    $groceries = Category::create(['slug' => 'groceries', 'name' => 'Groceries', 'kind' => 'custom', 'is_active' => true]);

    stdTxn($account, '50.00', '2026-01-15', ['name' => 'Kroger', 'category_id' => $groceries->id]);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'categories' => ['groceries'],
        'granularity' => 'month',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('series.0.total', '50.00')
            ->etc());
});

it('flags partial first and last periods', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);

    stdTxn($account, '10.00', '2026-01-20', ['name' => 'Gym', 'merchant_name' => 'Gym']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'month',
        'date_from' => '2026-01-15',
        'date_to' => '2026-02-10',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('series.0.is_partial', true)
            ->where('series.1.is_partial', true)
            ->etc());
});

it('excludes refunds by default but nets them with direction any', function () {
    $user = User::factory()->create();
    $account = stdAccount($user);

    stdTxn($account, '50.00', '2026-01-10', ['name' => 'Gym', 'merchant_name' => 'Gym']);
    stdTxn($account, '-20.00', '2026-01-15', ['name' => 'Gym refund', 'merchant_name' => 'Gym']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'month',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('series.0.total', '50.00')->etc());

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'direction' => 'any',
        'granularity' => 'month',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('series.0.total', '30.00')->etc());
});

it('returns an all-zero series when nothing matches', function () {
    $user = User::factory()->create();
    stdAccount($user);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'nonexistent',
        'granularity' => 'month',
        'date_from' => '2026-01-01',
        'date_to' => '2026-02-28',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('series.0.total', '0.00')
            ->where('summary.total', '0.00')
            ->etc());
});

it('never returns another user\'s data', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    $strangerAccount = stdAccount($stranger);

    stdTxn($strangerAccount, '50.00', '2026-01-10', ['name' => 'Gym', 'merchant_name' => 'Gym']);

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'month',
        'date_from' => '2026-01-01',
        'date_to' => '2026-01-31',
    ])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('series.0.total', '0.00')->etc());
});

it('requires merchant or categories', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [])
        ->assertHasErrors(['merchant']);
});

it('rejects more than 120 periods', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, [
        'merchant' => 'gym',
        'granularity' => 'month',
        'date_from' => '2016-01-01',
        'date_to' => '2026-09-28',
    ])->assertHasErrors(['at most 120 periods']);
});

it('rejects an unknown granularity', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, ['merchant' => 'gym', 'granularity' => 'bogus'])
        ->assertHasErrors(['granularity']);
});

it('rejects amount_max above the cap', function () {
    $user = User::factory()->create();

    FinanceInsightsServer::actingAs($user)->tool(SpendingTrend::class, ['merchant' => 'gym', 'amount_max' => 1.0e20])
        ->assertHasErrors(['amount max']);
});
