<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\withToken;

// Deliberately no Gate::before bypass in this file — every other API test
// bypasses the shield-generated TransactionPolicy because it's not what
// they're exercising. This file proves the policy is genuinely wired into
// TransactionController (Gate::authorize), not just decorative: a token
// with the right Sanctum ability still isn't enough on its own if the
// owning user's account lacks the matching Spatie permission, and granting
// that permission is what flips the response to success.
function policyConnectionFor(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function policyAccountFor(Connection $connection): Account
{
    return Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
    ]);
}

function policyTransactionRow(Account $account, Connection $connection): Transaction
{
    return Transaction::create([
        'account_id' => $account->id,
        'connection_id' => $connection->id,
        'external_transaction_id' => 'txn-'.Str::random(12),
        'amount' => 42.50,
        'date' => now()->toDateString(),
        'name' => 'Whole Foods Market',
        'raw_payload' => [],
    ]);
}

it('denies index (403) when the owner has a valid viewAny token but lacks the Spatie permission', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['viewAny'])->plainTextToken;

    withToken($token)->getJson('/api/transactions')
        ->assertForbidden();
});

it('allows index once the owner is granted the matching Spatie permission', function () {
    $user = User::factory()->create();
    Permission::findOrCreate('ViewAny:Transaction', 'web');
    $user->givePermissionTo('ViewAny:Transaction');

    $token = $user->createToken('test', ['viewAny'])->plainTextToken;

    withToken($token)->getJson('/api/transactions')
        ->assertOk();
});

it('denies show (403) when the owner has a valid view token but lacks the Spatie permission', function () {
    $user = User::factory()->create();
    $connection = policyConnectionFor($user);
    $account = policyAccountFor($connection);
    $transaction = policyTransactionRow($account, $connection);

    $token = $user->createToken('test', ['view'])->plainTextToken;

    withToken($token)->getJson("/api/transactions/{$transaction->id}")
        ->assertForbidden();
});

it('allows show once the owner is granted the matching Spatie permission', function () {
    $user = User::factory()->create();
    $connection = policyConnectionFor($user);
    $account = policyAccountFor($connection);
    $transaction = policyTransactionRow($account, $connection);

    Permission::findOrCreate('View:Transaction', 'web');
    $user->givePermissionTo('View:Transaction');

    $token = $user->createToken('test', ['view'])->plainTextToken;

    withToken($token)->getJson("/api/transactions/{$transaction->id}")
        ->assertOk();
});
