<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ConnectionStatus;
use App\Models\Account;
use App\Models\Connection;
use App\Models\Institution;
use App\Models\User;
use App\Services\Simplefin\SimplefinClient;
use App\Support\Simplefin\ProviderAccount;
use App\Support\Simplefin\ProviderSyncPage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ConnectionService
{
    public function __construct(private readonly SimplefinClient $client) {}

    /**
     * Temporary single-user shortcut — retire once Filament auth assigns
     * connections to the logged-in user.
     */
    public function getDefaultUserId(): string
    {
        return User::query()->value('id') ?? throw new \RuntimeException('No seeded user found');
    }

    /**
     * Claims a setup token and does a first fetch to discover institutions and
     * accounts. If any discovered account already exists (matched by
     * external_account_id), this is treated as a credential refresh on the
     * existing connection rather than a new one — re-claiming the same
     * SimpleFin credential issues a NEW Access URL but the underlying account
     * ids stay stable, and accounts.external_account_id is globally unique.
     */
    public function createOrRefreshFromSetupToken(string $setupToken): Connection
    {
        $accessUrl = $this->client->claimSetupToken($setupToken);
        $page = $this->client->fetchAccountSet($accessUrl);

        return DB::transaction(function () use ($accessUrl, $page) {
            $extIds = collect($page->accounts)->map(fn (ProviderAccount $a) => $a->externalAccountId)->all();
            $existingConnectionId = Account::query()
                ->whereIn('external_account_id', $extIds)
                ->value('connection_id');

            if ($existingConnectionId !== null) {
                $connection = Connection::findOrFail($existingConnectionId);
                $connection->credential_encrypted = $accessUrl;
                $connection->status = ConnectionStatus::Active;
                $connection->save();
            } else {
                $connection = Connection::create([
                    'user_id' => $this->getDefaultUserId(),
                    'provider' => 'simplefin',
                    'credential_encrypted' => $accessUrl,
                    'status' => ConnectionStatus::Active,
                ]);
            }

            $this->upsertAccountsAndInstitutions($connection->id, $page);

            return $connection;
        });
    }

    /**
     * Upserts institutions (looked up by provider + external_org_id) and
     * their accounts for a connection. Transactions are NOT touched here —
     * that's a later phase's SyncService/UpsertTransactionsAction concern.
     */
    public function upsertAccountsAndInstitutions(string $connectionId, ProviderSyncPage $page): int
    {
        foreach ($page->accounts as $account) {
            $institution = $this->firstOrCreateInstitution($account);

            Account::updateOrCreate(
                ['external_account_id' => $account->externalAccountId],
                [
                    'connection_id' => $connectionId,
                    'institution_id' => $institution->id,
                    'name' => $account->name,
                    'iso_currency_code' => $account->isoCurrencyCode,
                    'current_balance' => $account->currentBalance,
                    'available_balance' => $account->availableBalance,
                    'balances_updated_at' => $account->balancesUpdatedAt,
                ]
            );
        }

        return count($page->accounts);
    }

    /**
     * institutions.external_org_id is a NOT NULL column with a unique index
     * on (provider, external_org_id) — see
     * database/migrations/2026_07_22_130001_create_institutions_table.php.
     * SimpleFin's ProviderInstitution DTO models external_org_id as nullable
     * because the protocol doesn't strictly guarantee an org id is present
     * (SimplefinClient already falls back to conn_id in the common case, so
     * this path is a defensive rarely-hit fallback, not the normal case).
     *
     * Postgres unique indexes treat NULLs as distinct, so if we ever did try
     * to firstOrCreate() with a null external_org_id, repeated calls would
     * each insert a new "duplicate" institution row instead of matching the
     * existing one — and the NOT NULL constraint would reject the insert
     * outright besides. When external_org_id is null, match on (provider,
     * name) instead, and synthesize a stable, name-derived external_org_id
     * placeholder so the NOT NULL constraint is satisfied without colliding
     * across differently-named institutions.
     */
    private function firstOrCreateInstitution(ProviderAccount $account): Institution
    {
        $institution = $account->institution;

        if ($institution->externalOrgId !== null) {
            return Institution::firstOrCreate(
                ['provider' => $institution->provider, 'external_org_id' => $institution->externalOrgId],
                ['name' => $institution->name ?? $institution->externalOrgId, 'url' => $institution->url],
            );
        }

        $name = $institution->name ?? 'Unknown institution';
        $placeholderOrgId = 'noorg:'.(Str::slug($name) ?: 'unknown');

        return Institution::firstOrCreate(
            ['provider' => $institution->provider, 'name' => $name],
            ['external_org_id' => $placeholderOrgId, 'url' => $institution->url],
        );
    }

    /**
     * The `encrypted` cast on credential_encrypted already decrypts on read —
     * this is just a named accessor, do not decrypt again.
     */
    public function decryptCredential(string $connectionId): string
    {
        return Connection::findOrFail($connectionId)->credential_encrypted
            ?? throw new \RuntimeException("Connection {$connectionId} has no credential");
    }

    public function listConnections(): Collection
    {
        return Connection::query()->with(['accounts', 'user'])->latest('updated_at')->get();
    }
}
