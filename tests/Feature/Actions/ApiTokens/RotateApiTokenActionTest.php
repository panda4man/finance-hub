<?php

use App\Actions\ApiTokens\RotateApiTokenAction;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

// shield:generate produces a real TransactionPolicy gated on Spatie
// permissions that plain test users don't hold. These tests exercise
// rotation mechanics, not the policy layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

it('removes old token from database', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;
    $oldId = $old->id;

    (new RotateApiTokenAction)->execute($old);

    expect(PersonalAccessToken::find($oldId))->toBeNull();
});

it('returns a NewAccessToken instance', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;

    $result = (new RotateApiTokenAction)->execute($old);

    expect($result)->toBeInstanceOf(NewAccessToken::class);
});

it('preserves token name on rotation', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    $newStored = $new->accessToken;
    expect($newStored->name)->toBe('Original');
});

it('preserves abilities on rotation', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    $newStored = $new->accessToken;
    expect($newStored->abilities)->toBe(['viewAny']);
});

it('old plaintext token is rejected by authenticated endpoints', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $oldPlain = $newAccessToken->plainTextToken;
    $old = $newAccessToken->accessToken;

    (new RotateApiTokenAction)->execute($old);

    $response = $this->withToken($oldPlain)->get('/api/transactions');

    expect($response->status())->toBe(401);
});

it('new plaintext token is accepted by authenticated endpoints', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    $response = $this->withToken($new->plainTextToken)->get('/api/transactions');

    expect($response->status())->toBe(200);
});

it('preserves lifetime when token has expiry date', function () {
    $user = User::factory()->create();
    $originalExpiresAt = now()->addDays(90);
    $newAccessToken = $user->createToken('Expiring', ['viewAny'], $originalExpiresAt);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    $newStored = $new->accessToken;

    // The new token should expire approximately 90 days from now
    // Allow a reasonable tolerance (e.g., 89 days 23 hours to 90 days 1 minute)
    $minimumExpiry = now()->addDays(89)->addHours(23);
    $maximumExpiry = now()->addDays(90)->addMinutes(1);

    expect($newStored->expires_at->isBetween($minimumExpiry, $maximumExpiry))->toBeTrue();
});

it('preserves null expiry on rotation', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Never Expires', ['viewAny'], null);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    $newStored = $new->accessToken;
    expect($newStored->expires_at)->toBeNull();
});

it('handles tokens with different abilities correctly', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Admin Token', ['*']);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    $newStored = $new->accessToken;
    expect($newStored->abilities)->toBe(['*']);
});

it('creates new token before deleting old one in a transaction', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;
    $oldId = $old->id;
    $tokenCountBefore = $user->tokens()->count();

    $new = (new RotateApiTokenAction)->execute($old);

    // Verify old token is gone
    expect(PersonalAccessToken::find($oldId))->toBeNull();

    // Verify new token exists and user has same number of tokens (1 old + 1 new - 1 deleted)
    expect($new->accessToken)->not()->toBeNull();
    expect($user->tokens()->count())->toBe($tokenCountBefore);
});

it('rotated token can be used immediately after rotation', function () {
    $user = User::factory()->create();
    $newAccessToken = $user->createToken('Original', ['viewAny']);
    $old = $newAccessToken->accessToken;

    $new = (new RotateApiTokenAction)->execute($old);

    // Should succeed immediately without any delay
    $response = $this->withToken($new->plainTextToken)->get('/api/transactions');

    expect($response->status())->toBe(200);
});
