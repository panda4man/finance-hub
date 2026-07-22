<?php

use App\Enums\ConnectionStatus;
use App\Filament\Resources\ConnectionResource\Pages\CreateConnection;
use App\Filament\Resources\ConnectionResource\Pages\ListConnections;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. These tests exercise resource behavior,
// not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function makeConnectionWithStatus(User $user, ConnectionStatus $status, string $provider = 'simplefin', ?string $credential = 'https://user:pass@bridge.example.com/simplefin'): Connection
{
    return Connection::create([
        'user_id' => $user->id,
        'provider' => $provider,
        'credential_encrypted' => $credential,
        'status' => $status,
    ]);
}

it('shows Sync now and Backfill for an active simplefin connection with a credential', function () {
    $user = User::factory()->create();
    $connection = makeConnectionWithStatus($user, ConnectionStatus::Active);

    actingAs($user);

    Livewire::test(ListConnections::class)
        ->assertTableActionVisible('sync', $connection)
        ->assertTableActionVisible('backfill', $connection);
});

it('hides Sync now and Backfill for a revoked connection', function () {
    $user = User::factory()->create();
    $connection = makeConnectionWithStatus($user, ConnectionStatus::Revoked);

    actingAs($user);

    Livewire::test(ListConnections::class)
        ->assertTableActionHidden('sync', $connection)
        ->assertTableActionHidden('backfill', $connection);
});

it('hides Sync now and Backfill for a login_required connection', function () {
    $user = User::factory()->create();
    $connection = makeConnectionWithStatus($user, ConnectionStatus::LoginRequired);

    actingAs($user);

    Livewire::test(ListConnections::class)
        ->assertTableActionHidden('sync', $connection)
        ->assertTableActionHidden('backfill', $connection);
});

it('hides Sync now and Backfill for a non-simplefin provider', function () {
    $user = User::factory()->create();
    $connection = makeConnectionWithStatus($user, ConnectionStatus::Active, provider: 'plaid');

    actingAs($user);

    Livewire::test(ListConnections::class)
        ->assertTableActionHidden('sync', $connection)
        ->assertTableActionHidden('backfill', $connection);
});

it('hides Sync now and Backfill when there is no credential', function () {
    $user = User::factory()->create();
    $connection = makeConnectionWithStatus($user, ConnectionStatus::Active, credential: null);

    actingAs($user);

    Livewire::test(ListConnections::class)
        ->assertTableActionHidden('sync', $connection)
        ->assertTableActionHidden('backfill', $connection);
});

it('creates a connection by calling ConnectionService::createOrRefreshFromSetupToken with the raw token', function () {
    $user = User::factory()->create(['email' => 'default@example.com']);

    $claimUrl = 'https://bridge.example.com/simplefin/claim/tok-filament';
    $setupToken = base64_encode($claimUrl);
    $accessUrl = 'https://filament-user:filament-pass@bridge.example.com/simplefin';

    Http::fake([
        $claimUrl => Http::response($accessUrl, 200),
        '*/accounts*' => Http::response([
            'errlist' => [],
            'accounts' => [
                [
                    'id' => 'acc-filament-1',
                    'name' => 'Filament Checking',
                    'currency' => 'USD',
                    'balance' => '100.00',
                    'balance-date' => 1784635200,
                    'transactions' => [],
                ],
            ],
        ], 200),
    ]);

    actingAs($user);

    Livewire::test(CreateConnection::class)
        ->fillForm(['setup_token' => $setupToken])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Connection::count())->toBe(1);
    $connection = Connection::first();
    expect($connection->credential_encrypted)->toBe($accessUrl);
    expect($connection->status)->toBe(ConnectionStatus::Active);
});
