<?php

use App\Enums\ConnectionStatus;
use App\Jobs\RecategorizeAllJob;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;

it('--sync runs inline and reports scanned/updated counts', function () {
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
    $category = Category::create(['slug' => 'coffee', 'name' => 'Coffee']);
    CategoryRule::create([
        'pattern' => 'Coffee',
        'category_id' => $category->id,
    ]);
    Transaction::create([
        'account_id' => $account->id,
        'connection_id' => $connection->id,
        'external_transaction_id' => 'txn-1',
        'pending' => false,
        'amount' => '-5.00',
        'date' => '2024-01-01',
        'name' => 'Coffee Shop',
        'raw_payload' => [],
    ]);

    // Artisan::call() (not $this->artisan()) so Artisan::output() reflects
    // the real buffered output instead of a Mockery-wrapped OutputStyle
    // that only echoes text matched by an expectsOutput*() expectation.
    $exitCode = Artisan::call('categorize:recategorize', ['--sync' => true, '--json' => true]);
    expect($exitCode)->toBe(0);

    $row = json_decode(trim(Artisan::output()), true);

    expect($row['scanned'])->toBe(1);
    expect($row['updated'])->toBe(1);

    $transaction = Transaction::first();
    expect($transaction->category_id)->toBe($category->id);
});

it('without --sync dispatches RecategorizeAllJob to the configured queue', function () {
    Queue::fake();

    $exitCode = Artisan::call('categorize:recategorize', ['--json' => true]);
    expect($exitCode)->toBe(0);

    Queue::assertPushed(RecategorizeAllJob::class);

    $row = json_decode(trim(Artisan::output()), true);

    expect($row)->toBe(['dispatched' => true, 'queue' => config('finance.sync_queue')]);
});
