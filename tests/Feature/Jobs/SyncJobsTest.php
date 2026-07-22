<?php

use App\Actions\Categorization\RecategorizeAllAction;
use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Jobs\BackfillConnectionJob;
use App\Jobs\RecategorizeAllJob;
use App\Jobs\SyncConnectionJob;
use App\Models\Connection;
use App\Models\User;
use App\Services\SyncService;
use Illuminate\Support\Facades\Http;

function makeJobTestConnection(): Connection
{
    $user = User::factory()->create();

    return Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);
}

function jobFakeAccountSetPayload(): array
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

it('SyncConnectionJob runs on the configured queue and records a sync run', function () {
    $connection = makeJobTestConnection();
    Http::fake(['*/accounts*' => Http::response(jobFakeAccountSetPayload(), 200)]);

    $job = new SyncConnectionJob($connection->id, SyncTrigger::Scheduled);
    expect($job->queue)->toBe(config('finance.sync_queue'));
    expect($job->tries)->toBe(1);

    $job->handle(app(SyncService::class));

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->status)->toBe(SyncStatus::Success);
});

it('BackfillConnectionJob runs on the configured queue', function () {
    $connection = makeJobTestConnection();
    Http::fake(['*/accounts*' => Http::response(jobFakeAccountSetPayload(), 200)]);

    $job = new BackfillConnectionJob($connection->id);
    expect($job->queue)->toBe(config('finance.sync_queue'));
    expect($job->tries)->toBe(1);

    $job->handle(app(SyncService::class));

    $run = $connection->syncRuns()->latest('started_at')->first();
    expect($run->status)->toBe(SyncStatus::Success);
    expect($run->trigger)->toBe(SyncTrigger::Backfill);
});

it('RecategorizeAllJob runs on the configured queue', function () {
    $job = new RecategorizeAllJob;
    expect($job->queue)->toBe(config('finance.sync_queue'));
    expect($job->tries)->toBe(1);

    // Should not throw even with zero transactions.
    $job->handle(app(RecategorizeAllAction::class));
    expect(true)->toBeTrue();
});
