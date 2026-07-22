<?php

use App\Enums\ConnectionStatus;
use App\Enums\SyncTrigger;
use App\Jobs\SyncConnectionJob;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeSyncRunTestConnection(ConnectionStatus $status = ConnectionStatus::Active): Connection
{
    $user = User::factory()->create();

    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => $status,
    ]);
}

function syncRunAccountSetPayload(): array
{
    return [
        'errlist' => [],
        'connections' => [['conn_id' => 'conn-1', 'org_id' => 'org-1', 'name' => 'Test Bank']],
        'accounts' => [[
            'id' => 'acc-1',
            'name' => 'Checking',
            'conn_id' => 'conn-1',
            'currency' => 'USD',
            'balance' => '100.00',
            'transactions' => [],
        ]],
    ];
}

it('dispatches SyncConnectionJob for a single connection when --connection-id is given without --sync', function () {
    Queue::fake();
    $connection = makeSyncRunTestConnection();

    $this->artisan('sync:run', ['--connection-id' => $connection->id])
        ->assertExitCode(0);

    Queue::assertPushed(SyncConnectionJob::class, fn ($job) => $job->connectionId === $connection->id && $job->trigger === SyncTrigger::Manual);
});

it('dispatches SyncConnectionJob for every active connection when no options are given', function () {
    Queue::fake();
    $active = makeSyncRunTestConnection(ConnectionStatus::Active);
    $pending = makeSyncRunTestConnection(ConnectionStatus::PendingExpiration);
    $revoked = makeSyncRunTestConnection(ConnectionStatus::Revoked);

    $this->artisan('sync:run')->assertExitCode(0);

    Queue::assertPushed(SyncConnectionJob::class, 2);
    Queue::assertPushed(SyncConnectionJob::class, fn ($job) => $job->connectionId === $active->id);
    Queue::assertPushed(SyncConnectionJob::class, fn ($job) => $job->connectionId === $pending->id);
    Queue::assertNotPushed(SyncConnectionJob::class, fn ($job) => $job->connectionId === $revoked->id);
});

it('--json dispatched output reports queue and connection ids', function () {
    Queue::fake();
    $connection = makeSyncRunTestConnection();

    $this->artisan('sync:run', ['--connection-id' => $connection->id, '--json' => true])
        ->assertExitCode(0);

    Queue::assertPushed(SyncConnectionJob::class);
});

it('runs a single connection inline with --sync and reports the outcome', function () {
    $connection = makeSyncRunTestConnection();
    Http::fake(['*/accounts*' => Http::response(syncRunAccountSetPayload(), 200)]);

    $this->artisan('sync:run', ['--connection-id' => $connection->id, '--sync' => true])
        ->assertExitCode(0);

    $connection->refresh();
    expect($connection->last_successful_sync_at)->not->toBeNull();
    expect($connection->syncRuns()->count())->toBe(1);
});

it('runs all active connections inline with --sync and no --connection-id', function () {
    makeSyncRunTestConnection();
    makeSyncRunTestConnection();
    Http::fake(['*/accounts*' => Http::response(syncRunAccountSetPayload(), 200)]);

    $this->artisan('sync:run', ['--sync' => true])->assertExitCode(0);

    expect(SyncRun::count())->toBe(2);
});

it('uses the trigger option when recording the sync run', function () {
    $connection = makeSyncRunTestConnection();
    Http::fake(['*/accounts*' => Http::response(syncRunAccountSetPayload(), 200)]);

    $this->artisan('sync:run', ['--connection-id' => $connection->id, '--sync' => true, '--trigger' => 'webhook'])
        ->assertExitCode(0);

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->trigger)->toBe(SyncTrigger::Webhook);
});
