<?php

use App\Enums\ConnectionStatus;
use App\Enums\SyncStatus;
use App\Enums\SyncTrigger;
use App\Filament\Resources\SyncRunResource;
use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Filament\Widgets\SyncStatusOverview;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function makeSyncRun(Connection $connection, array $overrides = []): SyncRun
{
    return SyncRun::create(array_merge([
        'connection_id' => $connection->id,
        'trigger' => SyncTrigger::Manual,
        'status' => SyncStatus::Success,
        'started_at' => now(),
        'finished_at' => now(),
    ], $overrides));
}

it('scopes sync runs to the current owner and only allows viewing, never editing or deleting', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerConnection = Connection::create(['user_id' => $owner->id, 'provider' => 'simplefin', 'status' => ConnectionStatus::Active]);
    $ownerRun = makeSyncRun($ownerConnection);

    $strangerConnection = Connection::create(['user_id' => $stranger->id, 'provider' => 'simplefin', 'status' => ConnectionStatus::Active]);
    makeSyncRun($strangerConnection);

    actingAs($owner);

    expect(SyncRunResource::canCreate())->toBeFalse();
    expect(SyncRunResource::canEdit($ownerRun))->toBeFalse();
    expect(SyncRunResource::canDelete($ownerRun))->toBeFalse();

    Livewire::test(ListSyncRuns::class)
        ->assertCanSeeTableRecords([$ownerRun]);
});

it('renders the latest-sync-per-connection widget without error using a Postgres DISTINCT ON query', function () {
    $user = User::factory()->create();
    $connection = Connection::create(['user_id' => $user->id, 'provider' => 'simplefin', 'status' => ConnectionStatus::Active]);

    $older = makeSyncRun($connection, ['started_at' => now()->subDay(), 'status' => SyncStatus::Failed]);
    $newer = makeSyncRun($connection, ['started_at' => now(), 'status' => SyncStatus::Success]);

    actingAs($user);

    Livewire::test(SyncStatusOverview::class)
        ->assertCanSeeTableRecords([$newer])
        ->assertCanNotSeeTableRecords([$older]);
});
