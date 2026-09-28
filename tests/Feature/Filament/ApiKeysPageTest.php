<?php

use App\Filament\Pages\ApiKeys;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withToken;

// shield:generate produces real Policy classes gated on Spatie permissions
// that plain test users don't hold. This test exercises page/table
// behavior, not the authorization layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

it('shows only the current owner\'s keys', function () {
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $ownerToken = $owner->createToken('Owner key')->accessToken;
    $strangerToken = $stranger->createToken('Stranger key')->accessToken;

    actingAs($owner);

    Livewire::test(ApiKeys::class)
        ->assertCanSeeTableRecords([$ownerToken])
        ->assertCanNotSeeTableRecords([$strangerToken]);
});

it('creates a key, shows the plaintext once, and the key authenticates', function () {
    $owner = User::factory()->create();
    actingAs($owner);

    $component = Livewire::test(ApiKeys::class)
        ->callTableAction('create', data: [
            'name' => 'CLI',
            'abilities' => ['viewAny'],
            'expires_in' => '90',
        ]);

    $plain = $component->get('plainTextToken');

    expect($plain)->not()->toBeNull();
    expect(substr($plain, strrpos($plain, '|') + 1))->toStartWith('fhub_');

    $stored = PersonalAccessToken::query()->where('tokenable_id', $owner->id)->sole();
    expect($stored->name)->toBe('CLI');
    expect($stored->token)->not()->toBe($plain);

    withToken($plain)->getJson('/api/transactions')->assertOk();
});

it('does not expose the plaintext token again after the modal closes', function () {
    $owner = User::factory()->create();
    actingAs($owner);

    $component = Livewire::test(ApiKeys::class)
        ->callTableAction('create', data: [
            'name' => 'CLI',
            'abilities' => ['viewAny'],
            'expires_in' => '90',
        ]);

    $plain = $component->get('plainTextToken');

    $component->call('dismissPlainTextToken');

    expect($component->get('plainTextToken'))->toBeNull();

    $fresh = Livewire::test(ApiKeys::class);
    expect($fresh->get('plainTextToken'))->toBeNull();
    $fresh->assertDontSee($plain);
});

it('rotates a key: old row is replaced, new row keeps name and abilities', function () {
    // HTTP-level proof that the old token stops authenticating and the new
    // one works lives in RotateApiTokenActionTest (it exercises the same
    // RotateApiTokenAction directly). Mixing a real HTTP request *before* a
    // Livewire::test() call in the same test — with a guard reset in
    // between — breaks Livewire's table-record resolution in a way that's
    // a test-harness quirk, not app behavior, so this test stays
    // Livewire-only and checks the DB rows directly instead.
    $owner = User::factory()->create();
    actingAs($owner);

    $newAccessToken = $owner->createToken('CLI', ['viewAny']);
    $token = $newAccessToken->accessToken;

    $component = Livewire::test(ApiKeys::class)
        ->callTableAction('rotate', record: $token);

    $newPlain = $component->get('plainTextToken');
    expect($newPlain)->not()->toBeNull();

    expect(PersonalAccessToken::find($token->id))->toBeNull();

    $newRow = PersonalAccessToken::query()->where('tokenable_id', $owner->id)->sole();
    expect($newRow->name)->toBe('CLI');
    expect($newRow->abilities)->toBe(['viewAny']);
});

it('revokes a key: it stops working and disappears from the table', function () {
    $owner = User::factory()->create();
    actingAs($owner);

    $newAccessToken = $owner->createToken('CLI', ['viewAny']);
    $plain = $newAccessToken->plainTextToken;
    $token = $newAccessToken->accessToken;

    Livewire::test(ApiKeys::class)
        ->callTableAction('revoke', record: $token);

    expect(PersonalAccessToken::find($token->id))->toBeNull();

    withToken($plain)->getJson('/api/transactions')->assertUnauthorized();
});

it('requires a name to create a key', function () {
    $owner = User::factory()->create();
    actingAs($owner);

    Livewire::test(ApiKeys::class)
        ->callTableAction('create', data: [
            'name' => '',
            'abilities' => ['viewAny'],
            'expires_in' => '90',
        ])
        ->assertHasTableActionErrors(['name' => 'required']);
});
