<?php

use App\Enums\AccountType;
use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

it('adds account_type column to accounts table', function () {
    expect(Schema::hasColumn('accounts', 'account_type'))->toBeTrue();
});

it('account_type column is nullable', function () {
    expect(Schema::hasColumn('accounts', 'account_type'))->toBeTrue();

    // Create an account without setting account_type — should not error
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-'.Str::random(8),
        'name' => 'Test',
        'type' => 'depository',
        'subtype' => 'unknown',
        // No account_type specified
    ]);

    expect($account->account_type)->toBeNull();
});

it('can store and retrieve account_type as enum', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    // Test all four enum values
    $checkingAccount = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-checking',
        'name' => 'Checking',
        'account_type' => AccountType::Checking,
    ]);

    $savingsAccount = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-savings',
        'name' => 'Savings',
        'account_type' => AccountType::Savings,
    ]);

    $creditAccount = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-credit',
        'name' => 'Credit Card',
        'account_type' => AccountType::CreditCard,
    ]);

    $otherAccount = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-other',
        'name' => 'Other',
        'account_type' => AccountType::Other,
    ]);

    // Reload from database and verify casting works
    expect(Account::findOrFail($checkingAccount->id)->account_type)->toBe(AccountType::Checking);
    expect(Account::findOrFail($savingsAccount->id)->account_type)->toBe(AccountType::Savings);
    expect(Account::findOrFail($creditAccount->id)->account_type)->toBe(AccountType::CreditCard);
    expect(Account::findOrFail($otherAccount->id)->account_type)->toBe(AccountType::Other);
});

it('can store account_type as string value and retrieve as enum', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    // Store string values (as the migration does with raw queries)
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-test',
        'name' => 'Test',
        'account_type' => 'checking', // Store as string
    ]);

    // Reload and verify it's cast to enum
    $reloaded = Account::findOrFail($account->id);
    expect($reloaded->account_type)->toBeInstanceOf(AccountType::class);
    expect($reloaded->account_type)->toBe(AccountType::Checking);
});

it('backfills account_type from legacy type/subtype columns during the migration\'s up()', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    // RefreshDatabase already ran this migration once as part of the normal
    // suite bootstrap, so `account_type` already exists. Roll it back inside
    // this test's transaction so we can insert rows shaped exactly like real
    // pre-migration data (type/subtype populated, account_type column absent
    // entirely — not just null), then re-run up() for real and assert on
    // what its backfill UPDATE statements actually did to each row. This is
    // the only way to exercise the backfill logic itself rather than just
    // the column/cast that up() also happens to create.
    $migration = require database_path('migrations/2026_07_23_160000_add_account_type_to_accounts_table.php');
    $migration->down();

    expect(Schema::hasColumn('accounts', 'account_type'))->toBeFalse();

    $checkingId = (string) Str::uuid();
    $savingsId = (string) Str::uuid();
    $creditId = (string) Str::uuid();
    $unrelatedId = (string) Str::uuid();

    DB::table('accounts')->insert([
        [
            'id' => $checkingId,
            'connection_id' => $connection->id,
            'external_account_id' => 'acc-checking-'.Str::random(8),
            'name' => 'Checking',
            'type' => 'depository',
            'subtype' => 'checking',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $savingsId,
            'connection_id' => $connection->id,
            'external_account_id' => 'acc-savings-'.Str::random(8),
            'name' => 'Savings',
            'type' => 'depository',
            'subtype' => 'savings',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $creditId,
            'connection_id' => $connection->id,
            'external_account_id' => 'acc-credit-'.Str::random(8),
            'name' => 'Credit Card',
            // credit accounts backfill to credit_card regardless of subtype.
            'type' => 'credit',
            'subtype' => 'credit card',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => $unrelatedId,
            'connection_id' => $connection->id,
            'external_account_id' => 'acc-loan-'.Str::random(8),
            'name' => 'Loan',
            // Neither depository/checking, depository/savings, nor credit —
            // must be left untouched (stays null) by the backfill.
            'type' => 'loan',
            'subtype' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $migration->up();

    expect(DB::table('accounts')->where('id', $checkingId)->value('account_type'))->toBe('checking');
    expect(DB::table('accounts')->where('id', $savingsId)->value('account_type'))->toBe('savings');
    expect(DB::table('accounts')->where('id', $creditId)->value('account_type'))->toBe('credit_card');
    expect(DB::table('accounts')->where('id', $unrelatedId)->value('account_type'))->toBeNull();
});
