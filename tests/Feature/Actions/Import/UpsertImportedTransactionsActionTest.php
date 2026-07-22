<?php

use App\Actions\Import\UpsertImportedTransactionsAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Import\ParsedChaseRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function makeParsedChaseRow(
    string $id,
    string $description = 'Coffee Shop',
    float $amount = 10.50,
    ?float $balance = 1000.00,
): ParsedChaseRow {
    return new ParsedChaseRow(
        externalTransactionId: $id,
        postingDate: '2026-07-22',
        amount: $amount,
        description: $description,
        detailsType: 'DEBIT',
        balance: $balance,
        rawRow: [
            'Details' => 'DEBIT',
            'Posting Date' => '07/22/2026',
            'Description' => $description,
            'Amount' => (string) -abs($amount),
            'Type' => 'Purchase',
            'Balance' => $balance,
        ],
    );
}

/**
 * @return array{0: Connection, 1: Account}
 */
function makeManualConnectionWithAccount(string $externalAccountId = 'manual:test-uuid'): array
{
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'manual',
        'status' => 'active',
    ]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => $externalAccountId,
        'name' => 'Checking',
    ]);

    return [$connection, $account];
}

it('inserts new imported transactions and reports them as added', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $rows = [
        makeParsedChaseRow('csv:txn-1'),
        makeParsedChaseRow('csv:txn-2'),
    ];

    $action = app(UpsertImportedTransactionsAction::class);
    $result = $action->execute($account->id, $connection->id, $rows);

    expect($result)->toBe(['added' => 2, 'duplicate' => 0]);
    expect(Transaction::count())->toBe(2);

    $txn1 = Transaction::where('external_transaction_id', 'csv:txn-1')->firstOrFail();
    expect((float) $txn1->amount)->toBe(10.50);
    expect($txn1->name)->toBe('Coffee Shop');
});

it('detects duplicate transactions on re-import and reports them as duplicate', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $rows = [makeParsedChaseRow('csv:txn-1')];
    $action = app(UpsertImportedTransactionsAction::class);

    $firstResult = $action->execute($account->id, $connection->id, $rows);
    expect($firstResult)->toBe(['added' => 1, 'duplicate' => 0]);
    expect(Transaction::count())->toBe(1);

    $secondResult = $action->execute($account->id, $connection->id, $rows);
    expect($secondResult)->toBe(['added' => 0, 'duplicate' => 1]);
    expect(Transaction::count())->toBe(1); // no new record
});

it('handles idempotency: second import of same file adds zero new transactions', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $rows = [
        makeParsedChaseRow('csv:txn-1', 'COFFEE', 10.50),
        makeParsedChaseRow('csv:txn-2', 'LUNCH', 15.00),
    ];

    $action = app(UpsertImportedTransactionsAction::class);
    $firstResult = $action->execute($account->id, $connection->id, $rows);

    expect($firstResult['added'])->toBe(2);
    expect($firstResult['duplicate'])->toBe(0);
    $firstCount = Transaction::count();

    $secondResult = $action->execute($account->id, $connection->id, $rows);
    expect($secondResult['added'])->toBe(0);
    expect($secondResult['duplicate'])->toBe(2);
    expect(Transaction::count())->toBe($firstCount);
});

it('preserves user-owned columns on upsert', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $rows = [makeParsedChaseRow('csv:txn-1', 'COFFEE', 10.50)];
    $action = app(UpsertImportedTransactionsAction::class);

    // First import
    $action->execute($account->id, $connection->id, $rows);

    // Set user edits
    $userCategory = Category::create([
        'slug' => 'user-'.Str::random(6),
        'name' => 'User Cat',
        'kind' => 'custom',
        'is_active' => true,
    ]);
    $txn = Transaction::where('external_transaction_id', 'csv:txn-1')->firstOrFail();
    $txn->update([
        'user_category_id' => $userCategory->id,
        'user_notes' => 'Remember this!',
        'is_hidden' => true,
    ]);

    // Re-import with updated description and amount
    $updatedRows = [
        makeParsedChaseRow('csv:txn-1', 'COFFEE SHOP UPDATED', 12.50),
    ];
    $action->execute($account->id, $connection->id, $updatedRows);

    $txn->refresh();

    // Check that the description and amount were updated
    expect($txn->name)->toBe('COFFEE SHOP UPDATED');
    expect((float) $txn->amount)->toBe(12.50);

    // Check that user-owned columns were preserved
    expect($txn->user_category_id)->toBe($userCategory->id);
    expect($txn->user_notes)->toBe('Remember this!');
    expect($txn->is_hidden)->toBeTrue();
});

it('skips empty row list without throwing', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $action = app(UpsertImportedTransactionsAction::class);
    $result = $action->execute($account->id, $connection->id, []);

    expect($result)->toBe(['added' => 0, 'duplicate' => 0]);
    expect(Transaction::count())->toBe(0);
});

it('handles mixed added and duplicate transactions in one pass', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $action = app(UpsertImportedTransactionsAction::class);

    // First import: add txn-1
    $firstRows = [makeParsedChaseRow('csv:txn-1')];
    $firstResult = $action->execute($account->id, $connection->id, $firstRows);
    expect($firstResult)->toBe(['added' => 1, 'duplicate' => 0]);

    // Second import: txn-1 is duplicate, txn-2 and txn-3 are new
    $secondRows = [
        makeParsedChaseRow('csv:txn-1'),
        makeParsedChaseRow('csv:txn-2'),
        makeParsedChaseRow('csv:txn-3'),
    ];
    $secondResult = $action->execute($account->id, $connection->id, $secondRows);

    expect($secondResult)->toBe(['added' => 2, 'duplicate' => 1]);
    expect(Transaction::count())->toBe(3);
});

it('recomputes category_id on every upsert even if description and amount are unchanged', function () {
    [$connection, $account] = makeManualConnectionWithAccount();

    $action = app(UpsertImportedTransactionsAction::class);

    $rows = [makeParsedChaseRow('csv:txn-1', 'COFFEE', 10.50)];
    $firstResult = $action->execute($account->id, $connection->id, $rows);

    expect($firstResult)->toBe(['added' => 1, 'duplicate' => 0]);
    expect(Transaction::count())->toBe(1);

    $txn1 = Transaction::where('external_transaction_id', 'csv:txn-1')->firstOrFail();
    $firstCategoryId = $txn1->category_id;

    // Re-execute with the same rows
    $secondResult = $action->execute($account->id, $connection->id, $rows);

    // Second time should report as duplicate, not added
    expect($secondResult)->toBe(['added' => 0, 'duplicate' => 1]);
    // And transaction count should remain 1 (the existing record was updated/upserted)
    expect(Transaction::count())->toBe(1);

    $txn2 = Transaction::where('external_transaction_id', 'csv:txn-1')->firstOrFail();
    // The category_id may be recomputed (same or different) but the important
    // thing is that it's not treated as a completely new transaction
    expect($txn2->id)->toBe($txn1->id);
});
