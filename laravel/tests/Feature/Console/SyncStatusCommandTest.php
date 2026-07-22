<?php

use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;

// $this->artisan(...) (Laravel's PendingCommand test helper) swaps in a
// Mockery-wrapped OutputStyle that only records text matching an
// expectsOutput*() expectation set up in advance — it can't be used to
// capture and inspect an arbitrary JSON body afterwards. Artisan::call()
// runs the command through the real console kernel, whose output buffer
// Artisan::output() faithfully returns, so JSON-shape assertions use that
// instead.
function callAndDecodeJson(string $command, array $parameters = []): array
{
    $exitCode = Artisan::call($command, $parameters);
    expect($exitCode)->toBe(0);

    return json_decode(trim(Artisan::output()), true);
}

it('prints "No sync runs recorded yet." when there are none', function () {
    $this->artisan('sync:status')
        ->expectsOutputToContain('No sync runs recorded yet.')
        ->assertExitCode(0);
});

it('--json shape matches the old MCP wire shape (camelCase keys, ISO-8601 dates, nullable finishedAt)', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    $run = SyncRun::create([
        'connection_id' => $connection->id,
        'trigger' => SyncTrigger::Scheduled,
        'status' => SyncStatus::Success,
        'finished_at' => now(),
        'pages_fetched' => 3,
        'added_count' => 5,
        'modified_count' => 1,
        'removed_count' => 0,
        'accounts_upserted' => 2,
    ]);

    $rows = callAndDecodeJson('sync:status', ['--json' => true]);

    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row)->toHaveKeys([
        'id', 'connectionId', 'trigger', 'status', 'startedAt', 'finishedAt',
        'cursorBefore', 'cursorAfter', 'pagesFetched', 'addedCount', 'modifiedCount',
        'removedCount', 'accountsUpserted', 'errorCode', 'errorMessage', 'createdAt',
    ]);
    expect($row['id'])->toBe($run->id);
    expect($row['connectionId'])->toBe($connection->id);
    expect($row['trigger'])->toBe('scheduled');
    expect($row['status'])->toBe('success');
    expect($row['finishedAt'])->not->toBeNull();
    expect($row['pagesFetched'])->toBe(3);
    expect($row['addedCount'])->toBe(5);
});

it('a running (unfinished) run has a null finishedAt in --json output', function () {
    $user = User::factory()->create();
    $connection = Connection::create([
        'user_id' => $user->id,
        'provider' => 'simplefin',
        'credential_encrypted' => 'https://someuser:somepass@bridge.example.com/simplefin',
        'status' => ConnectionStatus::Active,
    ]);

    SyncRun::create([
        'connection_id' => $connection->id,
        'trigger' => SyncTrigger::Manual,
        'status' => SyncStatus::Running,
    ]);

    $rows = callAndDecodeJson('sync:status', ['--json' => true]);

    expect($rows[0]['finishedAt'])->toBeNull();
});
