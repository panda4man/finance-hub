<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\withToken;

// shield:generate produces a real TransactionPolicy gated on Spatie
// permissions that plain test users don't hold. These tests exercise the
// rate limiter, not the policy layer, so bypass it here.
beforeEach(fn () => Gate::before(fn () => true));

function rateLimitTokenFor(User $user, string $name = 'test', array $abilities = ['viewAny']): string
{
    return $user->createToken($name, $abilities)->plainTextToken;
}

it('allows 60 requests and rate-limits the 61st', function () {
    $user = User::factory()->create();
    $token = rateLimitTokenFor($user);

    for ($i = 1; $i <= 60; $i++) {
        $response = withToken($token)->getJson('/api/transactions');
        expect($response->getStatusCode())->not->toBe(429, "Request $i should not be rate-limited");

        // Assert X-RateLimit-Remaining header is present on first request
        if ($i === 1) {
            expect($response->headers->has('X-RateLimit-Remaining'))->toBeTrue();
        }
    }

    // 61st request should be rate-limited
    $response = withToken($token)->getJson('/api/transactions');
    expect($response->getStatusCode())->toBe(429);
});

it('isolates rate limits between different users', function () {
    $userA = User::factory()->create();
    $tokenA = rateLimitTokenFor($userA, 'tokenA');

    // Exhaust user A's quota
    for ($i = 1; $i <= 60; $i++) {
        $response = withToken($tokenA)->getJson('/api/transactions');
        expect($response->getStatusCode())->not->toBe(429, "Request $i should not be rate-limited");
    }

    // Confirm user A is rate-limited
    withToken($tokenA)->getJson('/api/transactions')
        ->assertStatus(429);

    // Forget cached guards so Sanctum re-resolves the user
    app('auth')->forgetGuards();

    // User B should still have requests available
    $userB = User::factory()->create();
    $tokenB = rateLimitTokenFor($userB, 'tokenB');

    $response = withToken($tokenB)->getJson('/api/transactions');
    expect($response->getStatusCode())->not->toBe(429, 'User B should have quota remaining');
});

it('resets rate limit after the window expires', function () {
    $user = User::factory()->create();
    $token = rateLimitTokenFor($user);

    // Exhaust the quota
    for ($i = 1; $i <= 60; $i++) {
        $response = withToken($token)->getJson('/api/transactions');
        expect($response->getStatusCode())->not->toBe(429, "Request $i should not be rate-limited");
    }

    // Confirm rate-limited
    withToken($token)->getJson('/api/transactions')
        ->assertStatus(429);

    // Travel 61 seconds into the future
    $this->travel(61)->seconds();

    // Should now have quota again
    $response = withToken($token)->getJson('/api/transactions');
    expect($response->getStatusCode())->not->toBe(429, 'Rate limit should reset after window expires');
});

it('shares rate limit between multiple tokens for the same user', function () {
    $user = User::factory()->create();
    $tokenA = rateLimitTokenFor($user, 'tokenA');
    $tokenB = rateLimitTokenFor($user, 'tokenB');

    // Exhaust the quota with token A
    for ($i = 1; $i <= 60; $i++) {
        $response = withToken($tokenA)->getJson('/api/transactions');
        expect($response->getStatusCode())->not->toBe(429, "Request $i should not be rate-limited");
    }

    // Confirm token A is rate-limited
    withToken($tokenA)->getJson('/api/transactions')
        ->assertStatus(429);

    // Forget cached guards so Sanctum re-resolves the user/token
    app('auth')->forgetGuards();

    // Token B should also be rate-limited (same user)
    $response = withToken($tokenB)->getJson('/api/transactions');
    expect($response->getStatusCode())->toBe(429, 'Token B should share the rate limit with token A (same user)');
});
