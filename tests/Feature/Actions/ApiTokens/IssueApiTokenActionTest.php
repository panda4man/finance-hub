<?php

use App\Actions\ApiTokens\IssueApiTokenAction;
use App\Models\User;
use Carbon\Carbon;
use Laravel\Sanctum\NewAccessToken;

it('returns a NewAccessToken instance', function () {
    $user = User::factory()->create();

    $result = (new IssueApiTokenAction)->execute($user, 'My Key', ['viewAny'], null);

    expect($result)->toBeInstanceOf(NewAccessToken::class);
});

it('stores token with provided name and abilities', function () {
    $user = User::factory()->create();

    $result = (new IssueApiTokenAction)->execute($user, 'My Key', ['viewAny', 'view'], null);

    $storedToken = $user->tokens()->first();

    expect($storedToken)->not()->toBeNull();
    expect($storedToken->name)->toBe('My Key');
    expect($storedToken->abilities)->toBe(['viewAny', 'view']);
});

it('stores token with null expires_at when not provided', function () {
    $user = User::factory()->create();

    $result = (new IssueApiTokenAction)->execute($user, 'My Key', ['viewAny'], null);

    $storedToken = $user->tokens()->first();

    expect($storedToken->expires_at)->toBeNull();
});

it('stores token with specified expires_at timestamp', function () {
    $user = User::factory()->create();
    $expiresAt = now()->addDays(90);

    $result = (new IssueApiTokenAction)->execute($user, 'Expiring Token', ['viewAny'], $expiresAt);

    $storedToken = $user->tokens()->first();

    expect($storedToken->expires_at->toDateTimeString())
        ->toBe($expiresAt->toDateTimeString());
});

it('handles different expiry times correctly', function () {
    $user = User::factory()->create();
    $expiresAt = Carbon::parse('2026-12-31 23:59:59');

    $result = (new IssueApiTokenAction)->execute($user, 'End of Year', ['viewAny'], $expiresAt);

    $storedToken = $user->tokens()->first();

    expect($storedToken->expires_at->equalTo($expiresAt))->toBeTrue();
});
