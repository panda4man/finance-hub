<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

function makeTransactionsListFixtures(): Account
{
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-1',
        'name' => 'Checking',
    ]);

    Transaction::create([
        'account_id' => $account->id,
        'connection_id' => $connection->id,
        'external_transaction_id' => 'txn-1',
        'pending' => false,
        'amount' => '-10.00',
        'date' => '2024-01-01',
        'name' => 'Coffee Shop',
        'raw_payload' => [],
    ]);

    Transaction::create([
        'account_id' => $account->id,
        'connection_id' => $connection->id,
        'external_transaction_id' => 'txn-2',
        'pending' => false,
        'amount' => '-99.50',
        'date' => '2024-01-02',
        'name' => 'Grocery Store',
        'merchant_name' => 'Grocery Co',
        'raw_payload' => [],
    ]);

    return $account;
}

it('rejects an invalid --sort-by with a FAILURE exit code and a message listing valid values', function () {
    makeTransactionsListFixtures();

    $this->artisan('transactions:list', ['--sort-by' => 'bogus'])
        ->expectsOutputToContain('date, amount, name, merchantName')
        ->assertExitCode(1);
});

it('rejects an invalid --order with a FAILURE exit code', function () {
    makeTransactionsListFixtures();

    $this->artisan('transactions:list', ['--order' => 'sideways'])
        ->assertExitCode(1);
});

it('--json envelope matches the wire shape and keeps amount as a string', function () {
    makeTransactionsListFixtures();

    // Artisan::call() (not $this->artisan()) so Artisan::output() reflects
    // the real buffered output instead of a Mockery-wrapped OutputStyle
    // that only echoes text matched by an expectsOutput*() expectation.
    $exitCode = Artisan::call('transactions:list', ['--json' => true]);
    expect($exitCode)->toBe(0);

    $envelope = json_decode(trim(Artisan::output()), true);

    expect($envelope)->toHaveKeys(['items', 'total', 'limit', 'offset']);
    expect($envelope['total'])->toBe(2);
    expect($envelope['limit'])->toBe(50);
    expect($envelope['offset'])->toBe(0);

    $item = $envelope['items'][0];
    expect($item)->toHaveKeys([
        'id', 'accountId', 'accountName', 'date', 'name', 'merchantName',
        'amount', 'isoCurrencyCode', 'pending', 'categorySlug', 'categoryName',
    ]);
    expect($item['amount'])->toBeString();
    expect($item['accountName'])->toBe('Checking');
});

it('respects --limit and --offset', function () {
    makeTransactionsListFixtures();

    $exitCode = Artisan::call('transactions:list', ['--json' => true, '--limit' => 1, '--offset' => 1]);
    expect($exitCode)->toBe(0);

    $envelope = json_decode(trim(Artisan::output()), true);

    expect($envelope['items'])->toHaveCount(1);
    expect($envelope['limit'])->toBe(1);
    expect($envelope['offset'])->toBe(1);
});

it('prints "No transactions found." when there are none', function () {
    $this->artisan('transactions:list')
        ->expectsOutputToContain('No transactions found.')
        ->assertExitCode(0);
});
