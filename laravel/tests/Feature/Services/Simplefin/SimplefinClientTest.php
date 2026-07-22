<?php

use App\Exceptions\SimplefinException;
use App\Services\Simplefin\SimplefinClient;
use Illuminate\Support\Facades\Http;

function fakeAccountSetResponse(): array
{
    return [
        'errlist' => [],
        'connections' => [
            ['conn_id' => 'conn-1', 'org_id' => 'org-abc', 'name' => 'Big Bank', 'org_url' => 'https://bigbank.example.com'],
        ],
        'accounts' => [
            [
                'id' => 'acc-1',
                'name' => 'Checking',
                'conn_id' => 'conn-1',
                'currency' => 'USD',
                'balance' => '100.00',
                'available-balance' => '95.00',
                'balance-date' => 1784635200,
                'transactions' => [
                    [
                        'id' => 'txn-pending',
                        'posted' => 0,
                        'transacted_at' => 1784635200,
                        'amount' => '-15.00',
                        'description' => 'PAYMENT TO CHASE CARD ENDING IN 4876 07/21',
                        'pending' => true,
                    ],
                    [
                        'id' => 'txn-posted',
                        'posted' => 1745337600,
                        'amount' => '10.00',
                        'description' => 'DUKEENERGY BILL PAY',
                        'pending' => false,
                    ],
                ],
            ],
        ],
    ];
}

it('claims a setup token by decoding, posting, and returning the access url', function () {
    $claimUrl = 'https://bridge.example.com/simplefin/claim/abc123';
    $setupToken = base64_encode($claimUrl);

    Http::fake([
        $claimUrl => Http::response('https://user:pass@bridge.example.com/simplefin', 200),
    ]);

    $client = new SimplefinClient;
    $accessUrl = $client->claimSetupToken($setupToken);

    expect($accessUrl)->toBe('https://user:pass@bridge.example.com/simplefin');

    Http::assertSent(fn ($request) => $request->url() === $claimUrl && $request->method() === 'POST');
});

it('throws invalidSetupToken for a token that does not decode to an http(s) url', function () {
    $client = new SimplefinClient;

    expect(fn () => $client->claimSetupToken(base64_encode('not-a-url')))
        ->toThrow(SimplefinException::class);
});

it('throws claimFailed when the claim request fails, without leaking the claim url', function () {
    $claimUrl = 'https://bridge.example.com/simplefin/claim/abc123';
    $setupToken = base64_encode($claimUrl);

    Http::fake([$claimUrl => Http::response('nope', 500)]);

    $client = new SimplefinClient;

    try {
        $client->claimSetupToken($setupToken);
        expect(false)->toBeTrue('expected SimplefinException to be thrown');
    } catch (SimplefinException $e) {
        expect($e->getMessage())->toContain('bridge.example.com');
        expect($e->getMessage())->toContain('500');
    }
});

it('fetches and maps an account set, including the sign flip and epoch-safe dates', function () {
    $credential = 'https://someuser:somepass@bridge.example.com/simplefin';

    Http::fake([
        '*/accounts*' => Http::response(fakeAccountSetResponse(), 200),
    ]);

    $client = new SimplefinClient;
    $page = $client->fetchAccountSet($credential);

    expect($page->accounts)->toHaveCount(1);
    $account = $page->accounts[0];
    expect($account->externalAccountId)->toBe('acc-1');
    expect($account->institution->externalOrgId)->toBe('org-abc');
    expect($account->institution->name)->toBe('Big Bank');
    expect($account->balancesUpdatedAt->timestamp)->toBe(1784635200);

    expect($account->transactions)->toHaveCount(2);
    [$pending, $posted] = $account->transactions;

    // (a) sign-flip: SimpleFin -15.00 (withdrawal) becomes +15.00 (finance-hub outflow).
    expect($pending->amount)->toBe('15.00');
    // SimpleFin +10.00 (deposit) becomes -10.00 (finance-hub inflow).
    expect($posted->amount)->toBe('-10.00');

    // (b) posted=0 (pending) must NOT produce a 1970 date — falls back to transacted_at.
    expect($pending->date->toDateString())->toBe('2026-07-21');
    expect($pending->date->year)->not->toBe(1970);
    expect($pending->pending)->toBeTrue();
    expect($pending->name)->toBe('PAYMENT TO CHASE CARD ENDING IN 4876 07/21');

    // (c) a normal posted timestamp round-trips correctly.
    expect($posted->date->timestamp)->toBe(1745337600);
    expect($posted->pending)->toBeFalse();

    expect($page->errors)->toBe([]);
});

it('maps errlist entries into readable strings without throwing', function () {
    $credential = 'https://someuser:somepass@bridge.example.com/simplefin';
    $body = fakeAccountSetResponse();
    $body['errlist'] = [['code' => 'act.failed', 'msg' => 'account temporarily unavailable', 'account_id' => 'acc-1']];

    Http::fake(['*/accounts*' => Http::response($body, 200)]);

    $page = (new SimplefinClient)->fetchAccountSet($credential);

    expect($page->errors)->toBe(['act.failed: account temporarily unavailable']);
    // Non-fatal: errors present alongside data does not throw.
    expect($page->accounts)->toHaveCount(1);
});

it('throws fetchFailed when the accounts request fails', function () {
    Http::fake(['*/accounts*' => Http::response('server error', 503)]);

    expect(fn () => (new SimplefinClient)->fetchAccountSet('https://u:p@bridge.example.com/simplefin'))
        ->toThrow(SimplefinException::class);
});

it('throws malformedResponse when the body is not JSON', function () {
    Http::fake(['*/accounts*' => Http::response('not json at all', 200)]);

    expect(fn () => (new SimplefinClient)->fetchAccountSet('https://u:p@bridge.example.com/simplefin'))
        ->toThrow(SimplefinException::class);
});

it('sends the request with credentials present, either embedded in the url or via a basic auth header', function () {
    $credential = 'https://someuser:somepass@bridge.example.com/simplefin';

    Http::fake(['*/accounts*' => Http::response(fakeAccountSetResponse(), 200)]);

    (new SimplefinClient)->fetchAccountSet($credential, ['startDate' => 1700000000, 'endDate' => 1800000000]);

    Http::assertSent(function ($request) {
        $hasEmbeddedUserinfo = str_contains($request->url(), 'someuser:somepass@');
        $hasAuthHeader = $request->hasHeader('Authorization');

        expect($hasEmbeddedUserinfo || $hasAuthHeader)->toBeTrue();
        expect($request->url())->toContain('start-date=1700000000');
        expect($request->url())->toContain('end-date=1800000000');
        expect($request->url())->toContain('version=2');
        expect($request->url())->toContain('pending=1');

        return true;
    });
});
