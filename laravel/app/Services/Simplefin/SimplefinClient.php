<?php

declare(strict_types=1);

namespace App\Services\Simplefin;

use App\Exceptions\SimplefinException;
use App\Support\Simplefin\ProviderAccount;
use App\Support\Simplefin\ProviderInstitution;
use App\Support\Simplefin\ProviderSyncPage;
use App\Support\Simplefin\ProviderTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the SimpleFin protocol (https://www.simplefin.org/protocol.html).
 * No SDK exists — auth is entirely embedded in the per-connection Access URL
 * claimed once from a user-supplied setup token.
 */
final class SimplefinClient
{
    /**
     * Decodes a one-time setup token and claims it for a permanent Access URL.
     */
    public function claimSetupToken(string $setupToken): string
    {
        $claimUrl = base64_decode(trim($setupToken), strict: true);
        $scheme = $claimUrl !== false ? parse_url($claimUrl, PHP_URL_SCHEME) : null;

        if ($claimUrl === false || ! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw SimplefinException::invalidSetupToken();
        }

        $config = config('services.simplefin');

        $response = Http::timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout'])
            ->post($claimUrl);

        if ($response->failed()) {
            throw SimplefinException::claimFailed($claimUrl, $response->status());
        }

        $accessUrl = trim($response->body());
        $host = parse_url($accessUrl, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw SimplefinException::malformedAccessUrl();
        }

        return $accessUrl;
    }

    /**
     * Fetches balances + transactions for every account under this credential
     * in a single call — the protocol has no separate per-institution or
     * paginated transaction endpoint.
     *
     * @param  array{startDate?: int, endDate?: int}  $opts  Unix seconds.
     */
    public function fetchAccountSet(string $credential, array $opts = []): ProviderSyncPage
    {
        $config = config('services.simplefin');
        $url = rtrim($credential, '/').'/accounts';

        // `version=2` requests the SimpleFin v2.0.0 `connections[]` response
        // shape (org_id/org_url/name) that toProviderSyncPage() below relies
        // on; `pending=1` asks the bridge to include still-pending transactions.
        // Neither is optional — this mirrors the existing, already-verified
        // NestJS SimpleFin client (src/simplefin/simplefin.service.ts).
        $query = ['version' => 2, 'pending' => 1];
        if (isset($opts['startDate'])) {
            $query['start-date'] = $opts['startDate'];
        }
        if (isset($opts['endDate'])) {
            $query['end-date'] = $opts['endDate'];
        }

        // The Access URL embeds HTTP basic-auth as `user:pass@host` userinfo.
        // VERIFIED (real, non-faked request against a local echo server —
        // Http::fake() can't be used to check this, it intercepts above the
        // transport layer that performs the conversion): Laravel's Http
        // facade / Guzzle's curl handler automatically turns embedded
        // userinfo into an `Authorization: Basic ...` header on the wire.
        // No manual parse_url()/withBasicAuth() needed — the URL is passed
        // through with its userinfo intact.
        $response = Http::timeout($config['timeout'])
            ->connectTimeout($config['connect_timeout'])
            ->retry($config['retries'], 500, throw: false)
            ->get($url, $query);

        if ($response->failed()) {
            $body = $response->json();
            $errlistCodes = is_array($body) ? $this->extractErrlistCodes($body) : [];

            throw SimplefinException::fetchFailed($response->status(), $errlistCodes);
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw SimplefinException::malformedResponse();
        }

        return $this->toProviderSyncPage($json);
    }

    /**
     * @param  array<string, mixed>  $json
     * @return list<string>
     */
    private function extractErrlistCodes(array $json): array
    {
        return array_values(array_filter(array_map(
            static fn ($entry) => is_array($entry) ? ($entry['code'] ?? null) : null,
            $json['errlist'] ?? []
        )));
    }

    /**
     * @param  array<string, mixed>  $json
     */
    private function toProviderSyncPage(array $json): ProviderSyncPage
    {
        // Real SimpleFin responses report errors via `errlist`: a list of
        // {code, msg, conn_id?, account_id?} objects — NOT a flat list of
        // strings as one might assume. Flatten each entry to a string to
        // match this DTO's `list<string>` shape.
        $errors = array_map(
            static function ($entry): string {
                if (! is_array($entry)) {
                    return (string) $entry;
                }

                $code = $entry['code'] ?? 'unknown';
                $msg = $entry['msg'] ?? '';

                return trim("{$code}: {$msg}", ': ');
            },
            $json['errlist'] ?? []
        );

        // gen.auth*/con.auth* errlist codes mean the credential itself needs
        // re-authing — that's a hard failure the caller (SyncService) must act
        // on, not a soft, non-fatal error to carry alongside otherwise-good
        // data in ProviderSyncPage->errors.
        $errlistCodes = $this->extractErrlistCodes($json);
        $authErrorCodes = array_values(array_filter(
            $errlistCodes,
            static fn (string $code) => str_starts_with($code, 'gen.auth') || str_starts_with($code, 'con.auth'),
        ));

        if ($authErrorCodes !== []) {
            throw SimplefinException::authError($authErrorCodes);
        }

        // Institution/org data comes from a top-level `connections[]` array
        // (SimpleFin v2.0.0's "Connection" object replaced the older
        // "Organization" object), keyed by `conn_id` — NOT from an `org` key
        // nested under each account.
        $connectionsByConnId = [];
        foreach (($json['connections'] ?? []) as $conn) {
            if (is_array($conn) && isset($conn['conn_id'])) {
                $connectionsByConnId[$conn['conn_id']] = $conn;
            }
        }

        $accounts = [];
        foreach (($json['accounts'] ?? []) as $account) {
            if (! is_array($account)) {
                continue;
            }

            $connId = $account['conn_id'] ?? null;
            $conn = $connId !== null ? ($connectionsByConnId[$connId] ?? null) : null;

            $institution = new ProviderInstitution(
                provider: 'simplefin',
                // Falls back to conn_id when no matching `connections[]` entry
                // was returned — some SimpleFin bridges omit that array
                // entirely, but conn_id is always present on the account.
                externalOrgId: $conn['org_id'] ?? $connId,
                name: $conn['name'] ?? $connId,
                url: $conn['org_url'] ?? null,
            );

            $rawTransactions = array_values(array_filter($account['transactions'] ?? [], 'is_array'));

            $accounts[] = new ProviderAccount(
                externalAccountId: (string) $account['id'],
                name: (string) ($account['name'] ?? ''),
                isoCurrencyCode: (string) ($account['currency'] ?? ''),
                currentBalance: isset($account['balance']) ? (string) $account['balance'] : null,
                availableBalance: isset($account['available-balance']) ? (string) $account['available-balance'] : null,
                balancesUpdatedAt: $this->safeTimestamp($account['balance-date'] ?? null),
                institution: $institution,
                transactions: array_map(fn (array $txn) => $this->toProviderTransaction($txn), $rawTransactions),
            );
        }

        return new ProviderSyncPage(errors: $errors, accounts: $accounts);
    }

    /**
     * @param  array<string, mixed>  $txn  Original, untouched provider payload — passed through as rawPayload.
     */
    private function toProviderTransaction(array $txn): ProviderTransaction
    {
        return new ProviderTransaction(
            externalTransactionId: (string) $txn['id'],
            pending: (bool) ($txn['pending'] ?? false),
            amount: $this->mapAmount((string) ($txn['amount'] ?? '0')),
            date: $this->derivePostedDate($txn),
            datetime: $this->safeTimestamp($txn['transacted_at'] ?? null),
            // SimpleFin's transaction description field is `description`, not `name`.
            name: (string) ($txn['description'] ?? ''),
            rawPayload: $txn,
        );
    }

    /**
     * VERIFY: SimpleFin sign convention assumed here based on typical
     * bridge-protocol behavior, not confirmed against a live response — check
     * https://www.simplefin.org/protocol.html and/or a real SimpleFin sandbox
     * response before this goes live with real bank data. If this is wrong,
     * every transaction's expense/income classification is inverted.
     *
     * SimpleFin: positive = deposit/money in, negative = withdrawal/money out.
     * finance-hub's internal convention (inherited from the prior Plaid
     * integration) is the opposite: positive = money leaving the account
     * (outflow/expense), negative = money coming in (inflow/income). Negate
     * to normalize into finance-hub's convention.
     */
    private function mapAmount(string $simplefinAmount): string
    {
        return sprintf('%.2f', -1 * (float) $simplefinAmount);
    }

    /**
     * Epoch-safe date derivation — guards against the exact regression the
     * NestJS app already hit and fixed (commit "Fix pending-transaction dates
     * landing on the Unix epoch"): a pending transaction's `posted` field is
     * typically `0` or absent, and must never be fed straight into a
     * from-unix-timestamp constructor, or it resolves to 1970-01-01.
     *
     * @param  array<string, mixed>  $txn
     */
    private function derivePostedDate(array $txn): CarbonImmutable
    {
        $posted = $txn['posted'] ?? null;
        if (is_numeric($posted) && (int) $posted > 0) {
            return CarbonImmutable::createFromTimestamp((int) $posted);
        }

        $transactedAt = $txn['transacted_at'] ?? null;
        if (is_numeric($transactedAt) && (int) $transactedAt > 0) {
            return CarbonImmutable::createFromTimestamp((int) $transactedAt);
        }

        return CarbonImmutable::now();
    }

    /**
     * Same 0-is-not-a-timestamp guard as derivePostedDate(), for optional
     * fields (`transacted_at` on transactions, `balance-date` on accounts)
     * that should be null rather than epoch when absent/zero.
     */
    private function safeTimestamp(mixed $value): ?CarbonImmutable
    {
        if (is_numeric($value) && (int) $value > 0) {
            return CarbonImmutable::createFromTimestamp((int) $value);
        }

        return null;
    }
}
