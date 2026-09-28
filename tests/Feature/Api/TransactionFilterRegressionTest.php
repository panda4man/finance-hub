<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

use function Pest\Laravel\withToken;

// Pins the REST API's current behavior (includes hidden/pending rows, inclusive
// date bounds, account_id + date filters intersect) so the upcoming
// TransactionQueryService refactor can't silently change it. See
// TransactionIndexTest for why Gate is bypassed here.
beforeEach(fn () => Gate::before(fn () => true));

function regConnectionFor(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function regAccountFor(Connection $connection): Account
{
    return Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
    ]);
}

function regTransactionRow(Account $account, Connection $connection, array $overrides = []): Transaction
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

function regTokenFor(User $user, array $abilities = ['viewAny'], ?CarbonInterface $expiresAt = null): string
{
    return $user->createToken('test', $abilities, $expiresAt)->plainTextToken;
}

it('index still includes hidden transactions', function () {
    $user = User::factory()->create();
    $connection = regConnectionFor($user);
    $account = regAccountFor($connection);
    regTransactionRow($account, $connection, ['is_hidden' => true]);

    $response = withToken(regTokenFor($user))->getJson('/api/transactions');

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
});

it('index still includes pending transactions', function () {
    $user = User::factory()->create();
    $connection = regConnectionFor($user);
    $account = regAccountFor($connection);
    regTransactionRow($account, $connection, ['pending' => true]);

    $response = withToken(regTokenFor($user))->getJson('/api/transactions');

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
});

it('show still returns a hidden pending transaction', function () {
    $user = User::factory()->create();
    $connection = regConnectionFor($user);
    $account = regAccountFor($connection);
    $txn = regTransactionRow($account, $connection, ['is_hidden' => true, 'pending' => true]);

    $response = withToken(regTokenFor($user, ['view']))->getJson("/api/transactions/{$txn->id}");

    $response->assertOk();
});

it('date_from and date_to together are inclusive on both ends', function () {
    $user = User::factory()->create();
    $connection = regConnectionFor($user);
    $account = regAccountFor($connection);

    regTransactionRow($account, $connection, ['name' => 'D24', 'date' => '2026-09-24']);
    regTransactionRow($account, $connection, ['name' => 'D25', 'date' => '2026-09-25']);
    regTransactionRow($account, $connection, ['name' => 'D26', 'date' => '2026-09-26']);
    regTransactionRow($account, $connection, ['name' => 'D27', 'date' => '2026-09-27']);

    $response = withToken(regTokenFor($user))
        ->getJson('/api/transactions?date_from=2026-09-25&date_to=2026-09-26');

    $response->assertOk();
    expect($response['data'])->toHaveCount(2);
    expect(collect($response['data'])->pluck('name')->sort()->values()->all())
        ->toBe(['D25', 'D26']);
});

it('account_id combined with date filters intersects', function () {
    $user = User::factory()->create();
    $connection = regConnectionFor($user);
    $account1 = regAccountFor($connection);
    $account2 = regAccountFor($connection);

    regTransactionRow($account1, $connection, ['name' => 'A1-in', 'date' => '2026-09-25']);
    regTransactionRow($account1, $connection, ['name' => 'A1-out', 'date' => '2026-09-01']);
    regTransactionRow($account2, $connection, ['name' => 'A2-in', 'date' => '2026-09-25']);

    $response = withToken(regTokenFor($user))
        ->getJson("/api/transactions?account_id={$account1->id}&date_from=2026-09-20&date_to=2026-09-30");

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['name'])->toBe('A1-in');
});
