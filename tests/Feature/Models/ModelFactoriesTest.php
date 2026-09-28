<?php

use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;

it('creates a connection owned by a new user', function () {
    $connection = Connection::factory()->create();

    expect($connection->user)->toBeInstanceOf(User::class);
    expect($connection->status)->toBe(ConnectionStatus::Active);
    expect($connection->provider)->toBe('simplefin');
});

it('creates an account on a new connection with a checking type', function () {
    $account = Account::factory()->create();

    expect($account->account_type)->toBe(AccountType::Checking);
    expect($account->connection_id)->not->toBeNull();
    expect($account->institution_id)->toBeNull();
});

it('creates a transaction whose connection matches its account connection', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->connection_id)->toBe($transaction->account->connection_id);
});

it('derives connection_id from an explicitly provided account', function () {
    $account = Account::factory()->create();
    $transaction = Transaction::factory()->for($account)->create();

    expect($transaction->connection_id)->toBe($account->connection_id);
});

it('defaults to a visible, settled, non-removed outflow', function () {
    $transaction = Transaction::factory()->create();

    expect($transaction->pending)->toBeFalse();
    expect($transaction->is_hidden)->toBeFalse();
    expect($transaction->removed_at)->toBeNull();
    expect((float) $transaction->amount)->toBeGreaterThan(0);
});

it('applies pending, hidden and removed states', function () {
    expect(Transaction::factory()->pending()->create()->pending)->toBeTrue();
    expect(Transaction::factory()->hidden()->create()->is_hidden)->toBeTrue();
    expect(Transaction::factory()->removed()->create()->removed_at)->not->toBeNull();
});

it('generates unique external ids', function () {
    $transactions = Transaction::factory()->count(5)->create();

    expect($transactions->pluck('external_transaction_id')->unique())->toHaveCount(5);
});
