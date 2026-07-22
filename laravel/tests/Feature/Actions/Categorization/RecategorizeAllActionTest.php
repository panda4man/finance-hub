<?php

use App\Actions\Categorization\RecategorizeAllAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Str;

function makeTransactionForRecategorize(string $connectionId, string $accountId, string $externalId, string $name): Transaction
{
    return Transaction::create([
        'account_id' => $accountId,
        'connection_id' => $connectionId,
        'external_transaction_id' => $externalId,
        'pending' => false,
        'amount' => '10.00',
        'date' => '2026-07-10',
        'name' => $name,
        'raw_payload' => json_encode(['id' => $externalId]),
    ]);
}

it('recomputes category_id for every transaction and reports scanned/updated counts', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => 'active',
    ]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-1',
        'name' => 'Checking',
    ]);

    $matching = makeTransactionForRecategorize($connection->id, $account->id, 'txn-1', 'Coffee Shop');
    $nonMatching = makeTransactionForRecategorize($connection->id, $account->id, 'txn-2', 'Unrelated Store');

    $category = Category::create(['slug' => 'coffee-'.Str::random(6), 'name' => 'Coffee', 'is_active' => true]);
    CategoryRule::create([
        'pattern' => 'coffee shop',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    $result = app(RecategorizeAllAction::class)->execute();

    expect($result['scanned'])->toBe(2);
    expect($result['updated'])->toBe(1);

    $matching->refresh();
    $nonMatching->refresh();
    expect($matching->category_id)->toBe($category->id);
    expect($nonMatching->category_id)->toBeNull();
});

it('never touches user-owned columns or last_modified_at', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'status' => 'active',
    ]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => 'acc-1',
        'name' => 'Checking',
    ]);

    $userCategory = Category::create(['slug' => 'user-'.Str::random(6), 'name' => 'User Cat', 'kind' => 'custom', 'is_active' => true]);
    $txn = makeTransactionForRecategorize($connection->id, $account->id, 'txn-1', 'Coffee Shop');
    $txn->update(['user_category_id' => $userCategory->id, 'user_notes' => 'keep me', 'is_hidden' => true]);
    // last_modified_at is DB-defaulted (not set by Eloquent on insert), so
    // refresh() to read back the value Postgres actually assigned.
    $txn->refresh();
    $originalLastModifiedAt = $txn->last_modified_at;

    $category = Category::create(['slug' => 'coffee-'.Str::random(6), 'name' => 'Coffee', 'is_active' => true]);
    CategoryRule::create([
        'pattern' => 'coffee shop',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $category->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    app(RecategorizeAllAction::class)->execute();

    $txn->refresh();
    expect($txn->category_id)->toBe($category->id);
    expect($txn->user_category_id)->toBe($userCategory->id);
    expect($txn->user_notes)->toBe('keep me');
    expect($txn->is_hidden)->toBeTrue();
    expect($txn->last_modified_at->equalTo($originalLastModifiedAt))->toBeTrue();
});
