<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

use function Pest\Laravel\withToken;

function idxConnectionFor(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function idxAccountFor(Connection $connection): Account
{
    return Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
    ]);
}

function idxTransactionRow(Account $account, Connection $connection, array $overrides = []): Transaction
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

function idxTokenFor(User $user, array $abilities = ['transactions:read'], ?CarbonInterface $expiresAt = null): string
{
    return $user->createToken('test', $abilities, $expiresAt)->plainTextToken;
}

it('owner only sees own transactions, not stranger transactions', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = idxConnectionFor($owner);
    $ownerAccount = idxAccountFor($ownerConnection);
    $ownerTxn = idxTransactionRow($ownerAccount, $ownerConnection, ['name' => 'Mine']);

    $strangerConnection = idxConnectionFor($stranger);
    $strangerAccount = idxAccountFor($strangerConnection);
    idxTransactionRow($strangerAccount, $strangerConnection, ['name' => 'Not mine']);

    $token = idxTokenFor($owner);

    $response = withToken($token)->getJson('/api/transactions');

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['name'])->toBe('Mine');
});

it('returns JSON structure with all required fields including nested account and category', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);
    idxTransactionRow($account, $connection);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions');

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            [
                'id',
                'date',
                'authorized_date',
                'datetime',
                'amount',
                'iso_currency_code',
                'name',
                'merchant_name',
                'pending',
                'is_hidden',
                'payment_channel',
                'user_notes',
                'account' => [
                    'id',
                    'name',
                    'type',
                    'institution',
                ],
                'category',
                'source_category',
                'created_at',
            ],
        ],
    ]);
});

it('does not include raw_payload in response', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);
    idxTransactionRow($account, $connection);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions');

    $response->assertOk();
    expect($response->json('data.0'))->not->toHaveKey('raw_payload');
});

it('uses effective category from userCategory when present over category', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);

    $sourceCategory = Category::create([
        'slug' => 'groceries',
        'name' => 'Groceries',
        'kind' => 'custom',
        'is_active' => true,
    ]);
    $userCategory = Category::create([
        'slug' => 'dining',
        'name' => 'Dining',
        'kind' => 'custom',
        'is_active' => true,
    ]);

    idxTransactionRow($account, $connection, [
        'category_id' => $sourceCategory->id,
        'user_category_id' => $userCategory->id,
    ]);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions');

    $response->assertOk();
    expect($response['data'][0]['category']['name'])->toBe('Dining');
});

it('excludes transactions with removed_at set', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);

    idxTransactionRow($account, $connection, ['name' => 'Active']);
    idxTransactionRow($account, $connection, [
        'name' => 'Removed',
        'removed_at' => now(),
    ]);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions');

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['name'])->toBe('Active');
});

it('orders by date descending then id descending', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);

    $txn1 = idxTransactionRow($account, $connection, [
        'name' => 'First',
        'date' => '2026-09-26',
    ]);
    $txn2 = idxTransactionRow($account, $connection, [
        'name' => 'Second',
        'date' => '2026-09-27',
    ]);
    $txn3 = idxTransactionRow($account, $connection, [
        'name' => 'Third',
        'date' => '2026-09-28',
    ]);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions');

    $response->assertOk();
    expect($response['data'][0]['name'])->toBe('Third');
    expect($response['data'][1]['name'])->toBe('Second');
    expect($response['data'][2]['name'])->toBe('First');
});

it('paginates correctly with per_page parameter', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);

    idxTransactionRow($account, $connection, ['name' => 'One']);
    idxTransactionRow($account, $connection, ['name' => 'Two']);
    idxTransactionRow($account, $connection, ['name' => 'Three']);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions?per_page=2');

    $response->assertOk();
    expect($response['meta']['total'])->toBe(3);
    expect($response['meta']['per_page'])->toBe(2);
    expect($response['data'])->toHaveCount(2);
    expect($response['links']['next'])->not->toBeNull();
});

it('rejects per_page greater than 100', function () {
    $user = User::factory()->create();
    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions?per_page=101');

    $response->assertUnprocessable();
});

it('rejects non-integer per_page', function () {
    $user = User::factory()->create();
    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions?per_page=abc');

    $response->assertUnprocessable();
});

it('filters by date_from correctly', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);

    idxTransactionRow($account, $connection, [
        'name' => 'Before',
        'date' => '2026-09-25',
    ]);
    idxTransactionRow($account, $connection, [
        'name' => 'After',
        'date' => '2026-09-27',
    ]);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions?date_from=2026-09-26');

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['name'])->toBe('After');
});

it('filters by date_to correctly', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);
    $account = idxAccountFor($connection);

    idxTransactionRow($account, $connection, [
        'name' => 'Before',
        'date' => '2026-09-25',
    ]);
    idxTransactionRow($account, $connection, [
        'name' => 'After',
        'date' => '2026-09-27',
    ]);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions?date_to=2026-09-26');

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['name'])->toBe('Before');
});

it('rejects date_to before date_from', function () {
    $user = User::factory()->create();
    $token = idxTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions?date_from=2026-09-27&date_to=2026-09-26');

    $response->assertUnprocessable();
});

it('filters by account_id correctly', function () {
    $user = User::factory()->create();
    $connection = idxConnectionFor($user);

    $account1 = idxAccountFor($connection);
    $account2 = idxAccountFor($connection);

    idxTransactionRow($account1, $connection, ['name' => 'Account1']);
    idxTransactionRow($account2, $connection, ['name' => 'Account2']);

    $token = idxTokenFor($user);

    $response = withToken($token)->getJson("/api/transactions?account_id={$account1->id}");

    $response->assertOk();
    expect($response['data'])->toHaveCount(1);
    expect($response['data'][0]['name'])->toBe('Account1');
});

it('returns empty data when stranger account_id is passed', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = idxConnectionFor($owner);
    $ownerAccount = idxAccountFor($ownerConnection);
    idxTransactionRow($ownerAccount, $ownerConnection);

    $strangerConnection = idxConnectionFor($stranger);
    $strangerAccount = idxAccountFor($strangerConnection);

    $token = idxTokenFor($owner);

    $response = withToken($token)->getJson("/api/transactions?account_id={$strangerAccount->id}");

    $response->assertOk();
    expect($response['data'])->toHaveCount(0);
});
