<?php

use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Exceptions\SimplefinException;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function makeConnection(): Connection
{
    $user = User::factory()->create();

    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

/**
 * @param  list<array<string, mixed>>  $transactions
 */
function accountSetPayload(array $transactions, string $externalAccountId = 'acc-1'): array
{
    return [
        'errlist' => [],
        'connections' => [
            ['conn_id' => 'conn-1', 'org_id' => 'org-1', 'name' => 'Test Bank'],
        ],
        'accounts' => [
            [
                'id' => $externalAccountId,
                'name' => 'Checking',
                'conn_id' => 'conn-1',
                'currency' => 'USD',
                'balance' => '100.00',
                'transactions' => $transactions,
            ],
        ],
    ];
}

function txnPayload(string $id, string $name = 'Coffee Shop', string $amount = '-10.00', int $posted = 1745337600): array
{
    return [
        'id' => $id,
        'posted' => $posted,
        'amount' => $amount,
        'description' => $name,
        'pending' => false,
    ];
}

it('successful sync updates last_successful_sync_at and creates a Success sync run', function () {
    $connection = makeConnection();

    Http::fake(['*/accounts*' => Http::response(accountSetPayload([txnPayload('txn-1')]), 200)]);

    $result = app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual);

    expect($result['status'])->toBe('success');
    expect($result['added'])->toBe(1);
    expect($result['modified'])->toBe(0);

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::Active);
    expect($connection->last_successful_sync_at)->not->toBeNull();

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->status)->toBe(SyncStatus::Success);
    expect($run->added_count)->toBe(1);
    expect($run->modified_count)->toBe(0);

    expect(Transaction::where('external_transaction_id', 'txn-1')->exists())->toBeTrue();
});

it('upsert preserves user_category_id/user_notes/is_hidden across a re-sync while updating category_id', function () {
    $connection = makeConnection();

    // A single Http::fake() call covering both requests made across this test:
    // calling Http::fake() a second time later would *stack* rather than
    // replace, and the earlier (first-registered) stub would keep winning.
    Http::fake([
        '*/accounts*' => Http::sequence()
            ->push(accountSetPayload([txnPayload('txn-1', 'Coffee Shop')]), 200)
            ->push(accountSetPayload([txnPayload('txn-1', 'Coffee Shop Updated')]), 200),
    ]);

    app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual);

    $txn = Transaction::where('external_transaction_id', 'txn-1')->firstOrFail();
    expect($txn->category_id)->toBeNull();

    $userCategory = Category::create([
        'slug' => 'user-cat-'.Str::random(6),
        'name' => 'My Category',
        'kind' => 'custom',
        'is_active' => true,
    ]);
    $txn->update([
        'user_category_id' => $userCategory->id,
        'user_notes' => 'do not touch',
        'is_hidden' => true,
    ]);

    $ruleCategory = Category::create(['slug' => 'coffee-'.Str::random(6), 'name' => 'Coffee', 'is_active' => true]);
    CategoryRule::create([
        'pattern' => 'coffee shop',
        'match_field' => 'name',
        'match_type' => 'substring',
        'amount_sign' => 'any',
        'category_id' => $ruleCategory->id,
        'priority' => 10,
        'source' => 'default',
        'is_active' => true,
    ]);

    $result = app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual);
    expect($result['modified'])->toBe(1);
    expect($result['added'])->toBe(0);

    $txn->refresh();
    expect($txn->name)->toBe('Coffee Shop Updated');
    expect($txn->category_id)->toBe($ruleCategory->id);
    expect($txn->user_category_id)->toBe($userCategory->id);
    expect($txn->user_notes)->toBe('do not touch');
    expect($txn->is_hidden)->toBeTrue();
});

it('retries a transient (5xx) error the configured number of times before succeeding', function () {
    config(['finance.retry_backoff_ms' => [1, 1, 1]]);

    $connection = makeConnection();

    Http::fake([
        '*/accounts*' => Http::sequence()
            ->push('server error', 503)
            ->push('server error', 503)
            ->push(accountSetPayload([txnPayload('txn-1')]), 200),
    ]);

    $result = app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual);

    expect($result['status'])->toBe('success');
    Http::assertSentCount(3);
});

it('fails after exhausting the configured retry attempts on a persistent transient error', function () {
    config(['finance.retry_backoff_ms' => [1, 1, 1]]);

    $connection = makeConnection();

    Http::fake(['*/accounts*' => Http::response('server error', 503)]);

    expect(fn () => app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual))
        ->toThrow(SimplefinException::class);

    Http::assertSentCount(4); // initial attempt + 3 retries

    $connection->refresh();
    expect($connection->last_successful_sync_at)->toBeNull();

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->status)->toBe(SyncStatus::Failed);
});

it('marks the connection login_required on a 402/403 auth error without retrying', function () {
    $connection = makeConnection();

    Http::fake(['*/accounts*' => Http::response('forbidden', 403)]);

    expect(fn () => app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual))
        ->toThrow(SimplefinException::class);

    Http::assertSentCount(1);

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::LoginRequired);
    expect($connection->last_successful_sync_at)->toBeNull();

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->status)->toBe(SyncStatus::Failed);
});

it('marks the connection login_required on a 200 response carrying a con.auth errlist code, without retrying', function () {
    $connection = makeConnection();

    $body = accountSetPayload([]);
    $body['errlist'] = [['code' => 'con.auth.expired', 'msg' => 're-auth needed']];

    Http::fake(['*/accounts*' => Http::response($body, 200)]);

    expect(fn () => app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual))
        ->toThrow(SimplefinException::class);

    Http::assertSentCount(1);

    $connection->refresh();
    expect($connection->status)->toBe(ConnectionStatus::LoginRequired);
});

describe('backfillConnection', function () {
    it('stops after the configured number of consecutive empty windows', function () {
        $connection = makeConnection();

        Http::fake([
            '*/accounts*' => Http::sequence()
                ->push(accountSetPayload([txnPayload('bf-1')]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([]), 200),
        ]);

        $result = app(SyncService::class)->backfillConnection($connection->id);

        expect($result['status'])->toBe('success');
        expect($result['pagesFetched'])->toBe(4);
        expect($result['added'])->toBe(1);
        Http::assertSentCount(4);

        expect(Transaction::where('external_transaction_id', 'bf-1')->exists())->toBeTrue();
    });

    it('does not stop on a single dormant window if an older window still has transactions', function () {
        $connection = makeConnection();

        Http::fake([
            '*/accounts*' => Http::sequence()
                ->push(accountSetPayload([txnPayload('bf-recent')]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([txnPayload('bf-older')]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([]), 200),
        ]);

        $result = app(SyncService::class)->backfillConnection($connection->id);

        expect($result['status'])->toBe('success');
        expect($result['added'])->toBe(2);
        expect(Transaction::whereIn('external_transaction_id', ['bf-recent', 'bf-older'])->count())->toBe(2);
    });

    it('sets last_successful_sync_at on a natural stop only when it was previously null', function () {
        $connection = makeConnection();
        expect($connection->last_successful_sync_at)->toBeNull();

        Http::fake(['*/accounts*' => Http::response(accountSetPayload([]), 200)]);

        $result = app(SyncService::class)->backfillConnection($connection->id);
        expect($result['status'])->toBe('success');

        $connection->refresh();
        expect($connection->last_successful_sync_at)->not->toBeNull();
    });

    it('leaves last_successful_sync_at untouched when it was already set', function () {
        $connection = makeConnection();

        // One fake covering the initial sync's request plus the 3 empty
        // backfill windows needed to reach a natural stop (see the "stops
        // after N consecutive empty windows" test above) — a second
        // Http::fake() call would stack instead of replacing the first.
        Http::fake([
            '*/accounts*' => Http::sequence()
                ->push(accountSetPayload([txnPayload('txn-first')]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([]), 200)
                ->push(accountSetPayload([]), 200),
        ]);

        app(SyncService::class)->syncConnection($connection->id, SyncTrigger::Manual);

        $connection->refresh();
        $syncedAt = $connection->last_successful_sync_at->getTimestamp();

        $result = app(SyncService::class)->backfillConnection($connection->id);
        expect($result['status'])->toBe('success');

        $connection->refresh();
        expect($connection->last_successful_sync_at->getTimestamp())->toBe($syncedAt);
    });

    it('leaves earlier committed windows intact when a later window fails', function () {
        $connection = makeConnection();

        Http::fake([
            '*/accounts*' => Http::sequence()
                ->push(accountSetPayload([txnPayload('bf-committed')]), 200)
                // Not valid JSON -> malformedResponse() -> status=null, not transient -> immediate failure, no retry.
                ->push('not json at all', 200),
        ]);

        expect(fn () => app(SyncService::class)->backfillConnection($connection->id))
            ->toThrow(SimplefinException::class);

        expect(Transaction::where('external_transaction_id', 'bf-committed')->exists())->toBeTrue();

        $run = $connection->syncRuns()->latest('started_at')->first();
        expect($run->status)->toBe(SyncStatus::Failed);
        expect($run->trigger)->toBe(SyncTrigger::Backfill);
    });
});
