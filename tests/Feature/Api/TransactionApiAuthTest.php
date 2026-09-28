<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

function apiConnectionFor(User $user): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://user:pass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function apiAccountFor(Connection $connection): Account
{
    return Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Checking',
    ]);
}

function apiTransactionRow(Account $account, Connection $connection, array $overrides = []): Transaction
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

function apiTokenFor(User $user, array $abilities = ['transactions:read'], ?CarbonInterface $expiresAt = null): string
{
    return $user->createToken('test', $abilities, $expiresAt)->plainTextToken;
}

it('returns 401 when no token is provided', function () {
    getJson('/api/transactions')
        ->assertUnauthorized();
});

it('returns 401 for plain GET request without Accept header and without token', function () {
    $this->get('/api/transactions')
        ->assertUnauthorized();
});

it('returns 401 when token is garbage (not a real token)', function () {
    withToken('garbage-not-a-real-token')->getJson('/api/transactions')
        ->assertUnauthorized();
});

it('returns 401 when PersonalAccessToken row is deleted after issuing', function () {
    $user = User::factory()->create();
    $token = $user->createToken('test', ['transactions:read']);
    $plain = $token->plainTextToken;
    $token->accessToken->delete();

    withToken($plain)->getJson('/api/transactions')
        ->assertUnauthorized();
});

it('returns 401 when token has expired', function () {
    $user = User::factory()->create();
    $expiresAt = now()->subDay();
    $token = apiTokenFor($user, ['transactions:read'], $expiresAt);

    withToken($token)->getJson('/api/transactions')
        ->assertUnauthorized();
});

it('returns 403 when token lacks transactions:read ability', function () {
    $user = User::factory()->create();
    $token = apiTokenFor($user, ['other:ability']);

    withToken($token)->getJson('/api/transactions')
        ->assertForbidden();
});

it('returns 401 when using session auth (web guard) without bearer token', function () {
    $user = User::factory()->create();
    actingAs($user);

    getJson('/api/transactions')
        ->assertUnauthorized();
});
