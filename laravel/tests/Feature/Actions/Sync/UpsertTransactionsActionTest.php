<?php

use App\Actions\Sync\UpsertTransactionsAction;
use App\Models\Account;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Simplefin\ProviderAccount;
use App\Support\Simplefin\ProviderInstitution;
use App\Support\Simplefin\ProviderSyncPage;
use App\Support\Simplefin\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

function makeProviderTransaction(string $id, string $name = 'Coffee Shop', string $amount = '10.00'): ProviderTransaction
{
    return new ProviderTransaction(
        externalTransactionId: $id,
        pending: false,
        amount: $amount,
        date: CarbonImmutable::parse('2026-07-10'),
        datetime: CarbonImmutable::parse('2026-07-10 08:00:00'),
        name: $name,
        rawPayload: ['id' => $id],
    );
}

/**
 * @param  list<ProviderTransaction>  $transactions
 */
function makeProviderAccount(string $externalAccountId, array $transactions): ProviderAccount
{
    return new ProviderAccount(
        externalAccountId: $externalAccountId,
        name: 'Checking',
        isoCurrencyCode: 'USD',
        currentBalance: '100.00',
        availableBalance: '100.00',
        balancesUpdatedAt: null,
        institution: new ProviderInstitution(provider: 'simplefin', externalOrgId: 'org-1', name: 'Test Bank', url: null),
        transactions: $transactions,
    );
}

/**
 * @return array{0: Connection, 1: Account}
 */
function makeConnectionWithAccount(string $externalAccountId = 'acc-1'): array
{
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => 'active',
    ]);
    $account = Account::create([
        'connection_id' => $connection->id,
        'external_account_id' => $externalAccountId,
        'name' => 'Checking',
    ]);

    return [$connection, $account];
}

it('inserts new transactions and reports them as added', function () {
    [$connection] = makeConnectionWithAccount();

    $page = new ProviderSyncPage(errors: [], accounts: [
        makeProviderAccount('acc-1', [makeProviderTransaction('txn-1'), makeProviderTransaction('txn-2')]),
    ]);

    $result = app(UpsertTransactionsAction::class)->execute($connection->id, $page);

    expect($result)->toBe(['added' => 2, 'modified' => 0]);
    expect(Transaction::count())->toBe(2);
});

it('reports existing transactions as modified and recomputes category_id even on an update-only pass', function () {
    [$connection] = makeConnectionWithAccount();
    $action = app(UpsertTransactionsAction::class);

    $page = new ProviderSyncPage(errors: [], accounts: [
        makeProviderAccount('acc-1', [makeProviderTransaction('txn-1', 'Coffee Shop')]),
    ]);
    $firstResult = $action->execute($connection->id, $page);
    expect($firstResult)->toBe(['added' => 1, 'modified' => 0]);

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

    $secondResult = $action->execute($connection->id, $page);
    expect($secondResult)->toBe(['added' => 0, 'modified' => 1]);

    $txn = Transaction::where('external_transaction_id', 'txn-1')->firstOrFail();
    expect($txn->category_id)->toBe($category->id);
});

it('preserves user-owned columns (user_category_id/user_notes/is_hidden) on upsert', function () {
    [$connection] = makeConnectionWithAccount();
    $action = app(UpsertTransactionsAction::class);

    $page = new ProviderSyncPage(errors: [], accounts: [
        makeProviderAccount('acc-1', [makeProviderTransaction('txn-1')]),
    ]);
    $action->execute($connection->id, $page);

    $userCategory = Category::create([
        'slug' => 'user-'.Str::random(6),
        'name' => 'User Cat',
        'kind' => 'custom',
        'is_active' => true,
    ]);
    $txn = Transaction::where('external_transaction_id', 'txn-1')->firstOrFail();
    $txn->update(['user_category_id' => $userCategory->id, 'user_notes' => 'keep me', 'is_hidden' => true]);

    $updatedPage = new ProviderSyncPage(errors: [], accounts: [
        makeProviderAccount('acc-1', [makeProviderTransaction('txn-1', 'Coffee Shop Updated', '12.50')]),
    ]);
    $action->execute($connection->id, $updatedPage);

    $txn->refresh();
    expect($txn->name)->toBe('Coffee Shop Updated');
    expect($txn->amount)->toBe('12.50');
    expect($txn->user_category_id)->toBe($userCategory->id);
    expect($txn->user_notes)->toBe('keep me');
    expect($txn->is_hidden)->toBeTrue();
});

it('skips transactions for an unknown account without throwing', function () {
    [$connection] = makeConnectionWithAccount('acc-1');

    $page = new ProviderSyncPage(errors: [], accounts: [
        makeProviderAccount('acc-unknown', [makeProviderTransaction('txn-orphan')]),
        makeProviderAccount('acc-1', [makeProviderTransaction('txn-1')]),
    ]);

    $result = app(UpsertTransactionsAction::class)->execute($connection->id, $page);

    expect($result)->toBe(['added' => 1, 'modified' => 0]);
    expect(Transaction::where('external_transaction_id', 'txn-orphan')->exists())->toBeFalse();
    expect(Transaction::where('external_transaction_id', 'txn-1')->exists())->toBeTrue();
});

it('returns zero counts and does nothing when the page has no transactions', function () {
    [$connection] = makeConnectionWithAccount();

    $page = new ProviderSyncPage(errors: [], accounts: [makeProviderAccount('acc-1', [])]);

    $result = app(UpsertTransactionsAction::class)->execute($connection->id, $page);

    expect($result)->toBe(['added' => 0, 'modified' => 0]);
    expect(Transaction::count())->toBe(0);
});
