<?php

use App\Models\Account;
use App\Models\Connection;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withHeaders;
use function Pest\Laravel\withToken;

// Deliberately no Gate::before bypass — this file proves the policy is
// genuinely wired into the /mcp/finance route, mirroring
// TransactionPolicyEnforcementTest's approach for the REST API.
function mcpInitializePayload(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'initialize',
        'params' => [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new stdClass,
            'clientInfo' => ['name' => 'pest', 'version' => '1.0'],
        ],
    ];
}

function mcpTokenFor(User $user, array $abilities = ['viewAny'], ?CarbonInterface $expiresAt = null): string
{
    return $user->createToken('test', $abilities, $expiresAt)->plainTextToken;
}

function mcpGrantViewAny(User $user): void
{
    Permission::findOrCreate('ViewAny:Transaction', 'web');
    $user->givePermissionTo('ViewAny:Transaction');
}

it('registers the finance MCP route by name', function () {
    expect(Route::has('mcp.finance'))->toBeTrue();
    expect(route('mcp.finance', absolute: false))->toBe('/mcp/finance');
});

it('rejects GET with 405 and Allow POST', function () {
    get('/mcp/finance')
        ->assertStatus(405)
        ->assertHeader('Allow', 'POST');
});

it('returns 401 with a Bearer WWW-Authenticate header when no token is provided', function () {
    $response = postJson('/mcp/finance', mcpInitializePayload());

    $response->assertStatus(401);
    $response->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
    expect($response->json('message'))->toBe('Unauthenticated.');
});

it('returns 401 for a garbage token', function () {
    withToken('garbage')->postJson('/mcp/finance', mcpInitializePayload())
        ->assertStatus(401);
});

it('returns 401 for an expired token', function () {
    $user = User::factory()->create();
    $token = mcpTokenFor($user, ['viewAny'], now()->subMinute());

    withToken($token)->postJson('/mcp/finance', mcpInitializePayload())
        ->assertStatus(401);
});

it('returns 401 for session (web guard) auth without a bearer token', function () {
    $user = User::factory()->create();

    actingAs($user)->postJson('/mcp/finance', mcpInitializePayload())
        ->assertStatus(401);
});

it('initializes the server for a viewAny token whose owner has the permission', function () {
    $user = User::factory()->create();
    mcpGrantViewAny($user);
    $token = mcpTokenFor($user);

    $response = withToken($token)->postJson('/mcp/finance', mcpInitializePayload());

    $response->assertOk();
    expect($response->json('result.serverInfo.name'))->toBe('Finance Insights');
    expect($response->json('result.protocolVersion'))->toBe('2025-06-18');
    expect($response->headers->has('MCP-Session-Id'))->toBeTrue();
});

it('lists the finance tools once authenticated', function () {
    $user = User::factory()->create();
    mcpGrantViewAny($user);
    $token = mcpTokenFor($user);

    $response = withToken($token)->postJson('/mcp/finance', [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/list',
    ]);

    $response->assertOk();
    expect(collect($response->json('result.tools'))->pluck('name')->all())->toBe([
        'list_accounts',
        'search_merchants',
        'search_transactions',
        'find_repeat_purchases',
        'spending_trend',
    ]);
});

it('returns 403 JSON when the token lacks the viewAny ability', function () {
    $user = User::factory()->create();
    mcpGrantViewAny($user);
    $token = mcpTokenFor($user, ['view']);

    $response = withToken($token)->postJson('/mcp/finance', mcpInitializePayload());

    $response->assertStatus(403);
    $response->assertJsonStructure(['message']);
});

it('returns 403 JSON when the owner lacks the ViewAny:Transaction permission', function () {
    $user = User::factory()->create();
    $token = mcpTokenFor($user, ['viewAny']);

    $response = withToken($token)->postJson('/mcp/finance', mcpInitializePayload());

    $response->assertStatus(403);
    $response->assertJsonStructure(['message']);
});

it('returns 401 (not a redirect to the Filament login) for a POST without an Accept header', function () {
    $response = post('/mcp/finance', mcpInitializePayload());

    $response->assertStatus(401);
    $response->assertHeaderMissing('Location');
    $response->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
});

it('returns 401 for an SSE-only Accept header', function () {
    withHeaders(['Accept' => 'text/event-stream'])->post('/mcp/finance', mcpInitializePayload())
        ->assertStatus(401);
});

it('shares the api rate limit bucket', function () {
    $user = User::factory()->create();
    mcpGrantViewAny($user);
    $token = mcpTokenFor($user);

    for ($i = 1; $i <= 60; $i++) {
        $response = withToken($token)->postJson('/mcp/finance', mcpInitializePayload());
        expect($response->getStatusCode())->not->toBe(429, "Request $i should not be rate-limited");
    }

    withToken($token)->postJson('/mcp/finance', mcpInitializePayload())
        ->assertStatus(429);
});

it('calls search_transactions over HTTP scoped to the token owner', function () {
    $user = User::factory()->create();
    $stranger = User::factory()->create();
    mcpGrantViewAny($user);
    $token = mcpTokenFor($user);

    $ownerAccount = Account::factory()->for(Connection::factory(['user_id' => $user->id]))->create();
    $strangerAccount = Account::factory()->for(Connection::factory(['user_id' => $stranger->id]))->create();

    $ownerTxn = Transaction::factory()->for($ownerAccount)->create(['name' => 'Mine']);
    Transaction::factory()->for($strangerAccount)->create(['name' => 'Not mine']);

    $response = withToken($token)->postJson('/mcp/finance', [
        'jsonrpc' => '2.0',
        'id' => 3,
        'method' => 'tools/call',
        'params' => ['name' => 'search_transactions', 'arguments' => []],
    ]);

    $response->assertOk();
    expect($response->json('result.isError'))->toBeFalse();

    $ids = collect($response->json('result.structuredContent.transactions'))->pluck('id')->all();
    expect($ids)->toBe([$ownerTxn->id]);
});
