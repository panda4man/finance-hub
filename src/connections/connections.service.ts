import { Inject, Injectable } from '@nestjs/common';
import { eq, desc, inArray, sql } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { users, institutions, connections, accounts } from '../db/schema';
import { CryptoService } from '../crypto/crypto.service';
import { SimplefinService } from '../simplefin/simplefin.service';
import type { ProviderSyncPage } from '../simplefin/simplefin.types';

const PROVIDER = 'simplefin';

@Injectable()
export class ConnectionsService {
  constructor(
    @Inject(DB) private readonly db: Database,
    private readonly crypto: CryptoService,
    private readonly simplefin: SimplefinService,
  ) {}

  /** Single-user for now: the one seeded users row. */
  async getDefaultUserId(): Promise<string> {
    const [user] = await this.db.select({ id: users.id }).from(users).limit(1);
    if (!user) {
      throw new Error('No user row found; run migrations (which seed the default user) first');
    }
    return user.id;
  }

  /**
   * Claims a setup token and does a first fetch to discover institutions and
   * accounts. If any discovered account already exists (matched by
   * `externalAccountId`), this is treated as a credential refresh on the
   * existing connection rather than a new one — SimpleFin re-auth has no
   * distinct "update mode", the user just pastes a fresh setup token.
   */
  async createOrRefreshFromSetupToken(setupToken: string) {
    const userId = await this.getDefaultUserId();
    const credential = await this.simplefin.claimSetupToken(setupToken);
    const page = await this.simplefin.fetchAccountSet(credential, { startDate: new Date() });

    const existingConnectionId = await this.findExistingConnection(page.accounts.map((a) => a.externalAccountId));
    const credentialEncrypted = this.crypto.encrypt(credential);

    return this.db.transaction(async (tx) => {
      let connectionId: string;
      if (existingConnectionId) {
        await tx
          .update(connections)
          .set({ credentialEncrypted, status: 'active', statusDetail: null, updatedAt: new Date() })
          .where(eq(connections.id, existingConnectionId));
        connectionId = existingConnectionId;
      } else {
        const [row] = await tx
          .insert(connections)
          .values({ userId, provider: PROVIDER, credentialEncrypted, status: 'active' })
          .returning({ id: connections.id });
        connectionId = row.id;
      }

      const accountCount = await this.upsertAccountsAndInstitutions(tx, connectionId, page);
      return { connectionId, accountCount };
    });
  }

  /**
   * Upserts institutions (looked up by provider + externalOrgId) and their
   * accounts for a connection. Shared by the initial claim flow above and by
   * SyncService's nightly sync, since a SimpleFin credential can surface new
   * institutions/accounts on any later sync, not just at creation.
   */
  async upsertAccountsAndInstitutions(
    tx: Database,
    connectionId: string,
    page: ProviderSyncPage,
  ): Promise<number> {
    const institutionIdByOrgId = new Map<string, string>();
    for (const institution of page.institutions) {
      const [row] = await tx
        .insert(institutions)
        .values({
          provider: PROVIDER,
          externalOrgId: institution.externalOrgId,
          name: institution.name,
          url: institution.url,
        })
        .onConflictDoUpdate({
          target: [institutions.provider, institutions.externalOrgId],
          set: { name: institution.name, url: institution.url, updatedAt: new Date() },
        })
        .returning({ id: institutions.id });
      institutionIdByOrgId.set(institution.externalOrgId, row.id);
    }

    if (page.accounts.length === 0) {
      return 0;
    }

    await tx
      .insert(accounts)
      .values(
        page.accounts.map((account) => ({
          connectionId,
          institutionId: institutionIdByOrgId.get(account.externalOrgId),
          externalAccountId: account.externalAccountId,
          name: account.name,
          availableBalance: account.availableBalance,
          currentBalance: account.currentBalance,
          isoCurrencyCode: account.isoCurrencyCode,
          balancesUpdatedAt: account.balancesUpdatedAt,
        })),
      )
      .onConflictDoUpdate({
        target: accounts.externalAccountId,
        set: {
          name: sql`excluded.name`,
          availableBalance: sql`excluded.available_balance`,
          currentBalance: sql`excluded.current_balance`,
          isoCurrencyCode: sql`excluded.iso_currency_code`,
          balancesUpdatedAt: sql`excluded.balances_updated_at`,
          updatedAt: new Date(),
        },
      });
    return page.accounts.length;
  }

  private async findExistingConnection(externalAccountIds: string[]): Promise<string | undefined> {
    if (externalAccountIds.length === 0) {
      return undefined;
    }
    const [existing] = await this.db
      .select({ connectionId: accounts.connectionId })
      .from(accounts)
      .where(inArray(accounts.externalAccountId, externalAccountIds))
      .limit(1);
    return existing?.connectionId;
  }

  async decryptCredential(connectionId: string): Promise<string> {
    const [connection] = await this.db
      .select({ credentialEncrypted: connections.credentialEncrypted })
      .from(connections)
      .where(eq(connections.id, connectionId))
      .limit(1);
    if (!connection?.credentialEncrypted) {
      throw new Error(`Connection ${connectionId} not found or has no stored credential`);
    }
    return this.crypto.decrypt(connection.credentialEncrypted);
  }

  async listConnections() {
    const rows = await this.db
      .select({
        connectionId: connections.id,
        status: connections.status,
        statusDetail: connections.statusDetail,
        lastSuccessfulSyncAt: connections.lastSuccessfulSyncAt,
        lastAttemptedSyncAt: connections.lastAttemptedSyncAt,
        createdAt: connections.createdAt,
        institutionName: institutions.name,
      })
      .from(connections)
      .leftJoin(accounts, eq(accounts.connectionId, connections.id))
      .leftJoin(institutions, eq(institutions.id, accounts.institutionId))
      .orderBy(desc(connections.createdAt));

    const byConnection = new Map<string, { institutionNames: Set<string> } & (typeof rows)[number]>();
    for (const row of rows) {
      const existing = byConnection.get(row.connectionId);
      if (existing) {
        if (row.institutionName) existing.institutionNames.add(row.institutionName);
      } else {
        byConnection.set(row.connectionId, {
          ...row,
          institutionNames: new Set(row.institutionName ? [row.institutionName] : []),
        });
      }
    }

    return [...byConnection.values()].map(({ institutionNames, institutionName: _institutionName, ...rest }) => ({
      ...rest,
      institutionNames: [...institutionNames],
    }));
  }
}
