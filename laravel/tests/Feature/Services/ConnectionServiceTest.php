<?php

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\ConnectionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

function fakeSimplefinFlow(string $claimUrl, string $accessUrl, array $accountSet): void
{
    Http::fake([
        $claimUrl => Http::response($accessUrl, 200),
        '*/accounts*' => Http::response($accountSet, 200),
    ]);
}

function sampleAccountSet(string $externalAccountId = 'acc-1', string $connId = 'conn-1'): array
{
    return [
        'errlist' => [],
        'connections' => [
            ['conn_id' => $connId, 'org_id' => 'org-'.$connId, 'name' => 'Big Bank', 'org_url' => null],
        ],
        'accounts' => [
            [
                'id' => $externalAccountId,
                'name' => 'Checking',
                'conn_id' => $connId,
                'currency' => 'USD',
                'balance' => '500.00',
                'available-balance' => '480.00',
                'balance-date' => 1784635200,
                'transactions' => [],
            ],
        ],
    ];
}

beforeEach(function () {
    User::factory()->create(['email' => 'default@example.com']);
});

it('creates a new connection, account, and institution on first claim', function () {
    $claimUrl = 'https://bridge.example.com/simplefin/claim/tok-1';
    $setupToken = base64_encode($claimUrl);
    $accessUrl = 'https://user1:pass1@bridge.example.com/simplefin';

    fakeSimplefinFlow($claimUrl, $accessUrl, sampleAccountSet('acc-1', 'conn-1'));

    $service = app(ConnectionService::class);
    $connection = $service->createOrRefreshFromSetupToken($setupToken);

    expect($connection)->toBeInstanceOf(Connection::class);
    expect($connection->status)->toBe(ConnectionStatus::Active);
    expect($connection->credential_encrypted)->toBe($accessUrl);
    expect(Connection::count())->toBe(1);
    expect(Account::count())->toBe(1);
    expect(Institution::count())->toBe(1);

    $account = Account::first();
    expect($account->external_account_id)->toBe('acc-1');
    expect($account->connection_id)->toBe($connection->id);
    expect((float) $account->current_balance)->toBe(500.00);

    $institution = Institution::first();
    expect($institution->external_org_id)->toBe('org-conn-1');
    expect($institution->name)->toBe('Big Bank');
});

it('treats a second claim with an overlapping account id as a refresh, not a new connection', function () {
    $claimUrl1 = 'https://bridge.example.com/simplefin/claim/tok-1';
    $setupToken1 = base64_encode($claimUrl1);
    $accessUrl1 = 'https://user1:pass1@bridge.example.com/simplefin';

    fakeSimplefinFlow($claimUrl1, $accessUrl1, sampleAccountSet('acc-shared', 'conn-1'));

    $service = app(ConnectionService::class);
    $first = $service->createOrRefreshFromSetupToken($setupToken1);

    // Second claim: SimpleFin issues a brand new Access URL, but the same
    // underlying account id ("acc-shared") comes back.
    $claimUrl2 = 'https://bridge.example.com/simplefin/claim/tok-2';
    $setupToken2 = base64_encode($claimUrl2);
    $accessUrl2 = 'https://user2:pass2@bridge.example.com/simplefin';

    fakeSimplefinFlow($claimUrl2, $accessUrl2, sampleAccountSet('acc-shared', 'conn-1'));

    $second = $service->createOrRefreshFromSetupToken($setupToken2);

    expect($second->id)->toBe($first->id);
    expect($second->credential_encrypted)->toBe($accessUrl2);
    expect(Connection::count())->toBe(1);
    expect(Account::count())->toBe(1);
    expect(Institution::count())->toBe(1);
});

it('creates a genuinely new connection when the second claim has no overlapping accounts', function () {
    // Distinct hosts per claim, since Http::fake() patterns registered later
    // don't reliably shadow earlier same-pattern registrations within one test.
    $claimUrl1 = 'https://bridge-one.example.com/simplefin/claim/tok-1';
    $setupToken1 = base64_encode($claimUrl1);
    $accessUrl1 = 'https://user1:pass1@bridge-one.example.com/simplefin';

    Http::fake([
        $claimUrl1 => Http::response($accessUrl1, 200),
        'bridge-one.example.com/*/accounts*' => Http::response(sampleAccountSet('acc-A', 'conn-A'), 200),
    ]);

    $service = app(ConnectionService::class);
    $first = $service->createOrRefreshFromSetupToken($setupToken1);

    $claimUrl2 = 'https://bridge-two.example.com/simplefin/claim/tok-2';
    $setupToken2 = base64_encode($claimUrl2);
    $accessUrl2 = 'https://user2:pass2@bridge-two.example.com/simplefin';

    Http::fake([
        $claimUrl2 => Http::response($accessUrl2, 200),
        'bridge-two.example.com/*/accounts*' => Http::response(sampleAccountSet('acc-B', 'conn-B'), 200),
    ]);

    $second = $service->createOrRefreshFromSetupToken($setupToken2);

    expect($second->id)->not->toBe($first->id);
    expect(Connection::count())->toBe(2);
    expect(Account::count())->toBe(2);
});

it('falls back to matching institutions by (provider, name) when external_org_id is null', function () {
    $claimUrl = 'https://bridge.example.com/simplefin/claim/tok-noorg';
    $setupToken = base64_encode($claimUrl);
    $accessUrl = 'https://user1:pass1@bridge.example.com/simplefin';

    // No `connections[]` entry at all AND no conn_id on the account, so
    // SimplefinClient cannot even fall back to conn_id — externalOrgId ends
    // up genuinely null, exercising ConnectionService's defensive fallback.
    $accountSet = [
        'errlist' => [],
        'accounts' => [
            [
                'id' => 'acc-noorg',
                'name' => 'Mystery Checking',
                'currency' => 'USD',
                'balance' => '10.00',
                'balance-date' => 1784635200,
                'transactions' => [],
            ],
        ],
    ];

    fakeSimplefinFlow($claimUrl, $accessUrl, $accountSet);

    $service = app(ConnectionService::class);
    $service->createOrRefreshFromSetupToken($setupToken);

    expect(Institution::count())->toBe(1);
    $institution = Institution::first();
    expect($institution->external_org_id)->not->toBeNull();
    // No org info AND no conn_id at all is a maximally-degraded, synthetic
    // edge case (the account's own name is not the institution's name).
    expect($institution->name)->toBe('Unknown institution');
});

it('decryptCredential returns the plaintext credential via the encrypted cast', function () {
    $claimUrl = 'https://bridge.example.com/simplefin/claim/tok-1';
    $setupToken = base64_encode($claimUrl);
    $accessUrl = 'https://user1:pass1@bridge.example.com/simplefin';

    fakeSimplefinFlow($claimUrl, $accessUrl, sampleAccountSet());

    $service = app(ConnectionService::class);
    $connection = $service->createOrRefreshFromSetupToken($setupToken);

    expect($service->decryptCredential($connection->id))->toBe($accessUrl);

    // Confirm it really is encrypted at rest, not stored as plaintext.
    $rawColumnValue = DB::table('connections')->where('id', $connection->id)->value('credential_encrypted');
    expect($rawColumnValue)->not->toBe($accessUrl);
});

it('listConnections eager-loads accounts and user', function () {
    $claimUrl = 'https://bridge.example.com/simplefin/claim/tok-1';
    $setupToken = base64_encode($claimUrl);
    $accessUrl = 'https://user1:pass1@bridge.example.com/simplefin';

    fakeSimplefinFlow($claimUrl, $accessUrl, sampleAccountSet());

    $service = app(ConnectionService::class);
    $service->createOrRefreshFromSetupToken($setupToken);

    $connections = $service->listConnections();

    expect($connections)->toHaveCount(1);
    expect($connections->first()->relationLoaded('accounts'))->toBeTrue();
    expect($connections->first()->relationLoaded('user'))->toBeTrue();
});
