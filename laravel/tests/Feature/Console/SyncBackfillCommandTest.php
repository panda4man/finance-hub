<?php

use App\Enums\ConnectionStatus;
use App\Enums\SyncTrigger;
use App\Jobs\BackfillConnectionJob;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function makeBackfillTestConnection(ConnectionStatus $status = ConnectionStatus::Active): Connection
{
    $user = User::factory()->create();

    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => $status,
    ]);
}

it('dispatches BackfillConnectionJob for a single connection when --connection-id is given without --sync', function () {
    Queue::fake();
    $connection = makeBackfillTestConnection();

    $this->artisan('sync:backfill', ['--connection-id' => $connection->id])
        ->assertExitCode(0);

    Queue::assertPushed(BackfillConnectionJob::class, fn ($job) => $job->connectionId === $connection->id);
});

it('dispatches BackfillConnectionJob for every active connection when no --connection-id is given', function () {
    Queue::fake();
    $active = makeBackfillTestConnection(ConnectionStatus::Active);
    makeBackfillTestConnection(ConnectionStatus::Revoked);

    $this->artisan('sync:backfill')->assertExitCode(0);

    Queue::assertPushed(BackfillConnectionJob::class, 1);
    Queue::assertPushed(BackfillConnectionJob::class, fn ($job) => $job->connectionId === $active->id);
});

it('runs a single connection inline with --sync and records a Backfill-trigger run', function () {
    $connection = makeBackfillTestConnection();
    Http::fake(['*/accounts*' => Http::response([
        'errlist' => [],
        'accounts' => [[
            'id' => 'acc-1',
            'name' => 'Checking',
            'currency' => 'USD',
            'balance' => '0.00',
            'transactions' => [],
        ]],
    ], 200)]);

    $this->artisan('sync:backfill', ['--connection-id' => $connection->id, '--sync' => true])
        ->assertExitCode(0);

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->trigger)->toBe(SyncTrigger::Backfill);
});

it('--json inline single output is the outcome object', function () {
    $connection = makeBackfillTestConnection();
    Http::fake(['*/accounts*' => Http::response([
        'errlist' => [],
        'accounts' => [[
            'id' => 'acc-1',
            'name' => 'Checking',
            'currency' => 'USD',
            'balance' => '0.00',
            'transactions' => [],
        ]],
    ], 200)]);

    // Artisan::call() (not $this->artisan()) so Artisan::output() reflects
    // the real buffered output instead of a Mockery-wrapped OutputStyle
    // that only echoes text matched by an expectsOutput*() expectation.
    $exitCode = Artisan::call('sync:backfill', ['--connection-id' => $connection->id, '--sync' => true, '--json' => true]);
    expect($exitCode)->toBe(0);

    $row = json_decode(trim(Artisan::output()), true);

    expect($row['connectionId'])->toBe($connection->id);
    expect($row['status'])->toBe('success');
});
