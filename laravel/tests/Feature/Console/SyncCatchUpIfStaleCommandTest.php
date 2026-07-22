<?php

use App\Enums\ConnectionStatus;
use App\Jobs\SyncConnectionJob;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

function makeCatchUpTestConnection(?Carbon $lastSuccessfulSyncAt, ConnectionStatus $status = ConnectionStatus::Active): Connection
{
    $user = User::factory()->create();

    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => $status,
        'last_successful_sync_at' => $lastSuccessfulSyncAt,
    ]);
}

it('no-ops when there are no active connections', function () {
    Queue::fake();

    $this->artisan('sync:catch-up-if-stale')
        ->expectsOutputToContain('Sync is current; nothing to do.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('no-ops when every active connection synced recently', function () {
    Queue::fake();
    makeCatchUpTestConnection(now()->subHours(1));

    $this->artisan('sync:catch-up-if-stale')
        ->expectsOutputToContain('Sync is current; nothing to do.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});

it('dispatches a catch-up sync when a connection has never synced', function () {
    Queue::fake();
    makeCatchUpTestConnection(null);

    $this->artisan('sync:catch-up-if-stale')
        ->expectsOutputToContain('Stale sync detected; dispatched catch-up sync.')
        ->assertExitCode(0);

    Queue::assertPushed(SyncConnectionJob::class);
});

it('dispatches a catch-up sync when the last successful sync is past the stale threshold', function () {
    Queue::fake();
    $thresholdHours = (int) config('finance.stale_sync_threshold_hours');
    makeCatchUpTestConnection(now()->subHours($thresholdHours + 1));

    $this->artisan('sync:catch-up-if-stale')
        ->expectsOutputToContain('Stale sync detected; dispatched catch-up sync.')
        ->assertExitCode(0);

    Queue::assertPushed(SyncConnectionJob::class);
});

it('ignores revoked connections when deciding staleness', function () {
    Queue::fake();
    makeCatchUpTestConnection(null, ConnectionStatus::Revoked);

    $this->artisan('sync:catch-up-if-stale')
        ->expectsOutputToContain('Sync is current; nothing to do.')
        ->assertExitCode(0);

    Queue::assertNothingPushed();
});
