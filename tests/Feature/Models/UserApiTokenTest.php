<?php

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

it('creates token with tokenable_id matching user uuid', function () {
    $user = User::factory()->create();

    $newAccessToken = $user->createToken('x');

    $storedToken = PersonalAccessToken::query()
        ->where('tokenable_id', $user->id)
        ->firstOrFail();

    expect($newAccessToken)->toBeInstanceOf(NewAccessToken::class);
    expect($storedToken->tokenable_id)->toBe($user->id);
});

it('stores hashed token not plaintext', function () {
    $user = User::factory()->create();

    $newAccessToken = $user->createToken('x');

    // Extract the plain text token part after the last |
    $plainTextToken = $newAccessToken->plainTextToken;
    $plainTextTokenPart = substr($plainTextToken, strrpos($plainTextToken, '|') + 1);
    $expectedHash = hash('sha256', $plainTextTokenPart);

    $storedToken = PersonalAccessToken::query()
        ->where('tokenable_id', $user->id)
        ->firstOrFail();

    expect($storedToken->token)->toBe($expectedHash);
    expect($storedToken->token)->not()->toBe($plainTextToken);
});

it('plaintext token starts with prefix', function () {
    $user = User::factory()->create();

    $newAccessToken = $user->createToken('x');

    // plaintext token format is id|token, extract the token part after |
    $plainTextToken = $newAccessToken->plainTextToken;
    $tokenPart = substr($plainTextToken, strrpos($plainTextToken, '|') + 1);

    expect($tokenPart)->toStartWith('fhub_');
});
