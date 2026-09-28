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

// shield:generate produces a real TransactionPolicy gated on Spatie
// permissions that plain test users don't hold. These tests exercise
// ownership scoping, not the policy layer — that's covered separately in
// TransactionPolicyEnforcementTest, which runs without this bypass — so
// bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function showConnectionFor(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function showAccountFor(Connection $connection): Account
{
    return Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
    ]);
}

function showTransactionRow(Account $account, Connection $connection, array $overrides = []): Transaction
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

function showTokenFor(User $user, array $abilities = ['view'], ?CarbonInterface $expiresAt = null): string
{
    return $user->createToken('test', $abilities, $expiresAt)->plainTextToken;
}

it('returns 200 with expected fields for owner own transaction', function () {
    $user = User::factory()->create();
    $connection = showConnectionFor($user);
    $account = showAccountFor($connection);
    $transaction = showTransactionRow($account, $connection);

    $token = showTokenFor($user);

    $response = withToken($token)->getJson("/api/transactions/{$transaction->id}");

    $response->assertOk();
    expect($response['data']['id'])->toBe($transaction->id);
    expect($response['data']['name'])->toBe($transaction->name);
    expect($response['data']['amount'])->toBe((string) $transaction->amount);
});

it('returns 404 for stranger transaction (not 403)', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = showConnectionFor($owner);
    $ownerAccount = showAccountFor($ownerConnection);
    $transaction = showTransactionRow($ownerAccount, $ownerConnection);

    $strangerToken = showTokenFor($stranger);

    $response = withToken($strangerToken)->getJson("/api/transactions/{$transaction->id}");

    $response->assertNotFound();
});

it('returns 404 for syntactically invalid UUID in URL', function () {
    $user = User::factory()->create();
    $token = showTokenFor($user);

    $response = withToken($token)->getJson('/api/transactions/not-a-uuid');

    $response->assertNotFound();
});

it('returns 403 when token lacks the view ability', function () {
    $user = User::factory()->create();
    $connection = showConnectionFor($user);
    $account = showAccountFor($connection);
    $transaction = showTransactionRow($account, $connection);

    $token = showTokenFor($user, ['viewAny']);

    $response = withToken($token)->getJson("/api/transactions/{$transaction->id}");

    $response->assertForbidden();
});

it('returns 404 for transaction with removed_at set', function () {
    $user = User::factory()->create();
    $connection = showConnectionFor($user);
    $account = showAccountFor($connection);
    $transaction = showTransactionRow($account, $connection, [
        'removed_at' => now(),
    ]);

    $token = showTokenFor($user);

    $response = withToken($token)->getJson("/api/transactions/{$transaction->id}");

    $response->assertNotFound();
});
