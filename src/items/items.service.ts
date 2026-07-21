import { Inject, Injectable } from '@nestjs/common';
import { eq, desc } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { users, institutions, plaidItems, accounts } from '../db/schema';
import { CryptoService } from '../crypto/crypto.service';
import { PlaidService } from '../plaid/plaid.service';

@Injectable()
export class ItemsService {
  constructor(
    @Inject(DB) private readonly db: Database,
    private readonly crypto: CryptoService,
    private readonly plaid: PlaidService,
  ) {}

  /** Single-user for now: the one seeded users row. */
  async getDefaultUserId(): Promise<string> {
    const [user] = await this.db.select({ id: users.id }).from(users).limit(1);
    if (!user) {
      throw new Error('No user row found; run migrations (which seed the default user) first');
    }
    return user.id;
  }

  async createFromPublicToken(publicToken: string) {
    const userId = await this.getDefaultUserId();
    const { accessToken, itemId: plaidItemId } = await this.plaid.exchangePublicToken(publicToken);

    const [item, plaidAccounts] = await Promise.all([
      this.plaid.getItem(accessToken),
      this.plaid.getAccounts(accessToken),
    ]);

    let institutionId: string | undefined;
    if (item.institution_id) {
      const institution = await this.plaid.getInstitution(item.institution_id);
      const [row] = await this.db
        .insert(institutions)
        .values({
          plaidInstitutionId: institution.institution_id,
          name: institution.name,
          url: institution.url ?? undefined,
          primaryColor: institution.primary_color ?? undefined,
          logoBase64: institution.logo ?? undefined,
        })
        .onConflictDoUpdate({
          target: institutions.plaidInstitutionId,
          set: { name: institution.name, updatedAt: new Date() },
        })
        .returning({ id: institutions.id });
      institutionId = row.id;
    }

    const accessTokenEncrypted = this.crypto.encrypt(accessToken);
    const [itemRow] = await this.db
      .insert(plaidItems)
      .values({
        userId,
        institutionId,
        plaidItemId,
        accessTokenEncrypted,
        status: 'active',
      })
      .returning({ id: plaidItems.id });

    if (plaidAccounts.length > 0) {
      await this.db.insert(accounts).values(
        plaidAccounts.map((account) => ({
          itemId: itemRow.id,
          plaidAccountId: account.account_id,
          name: account.name,
          officialName: account.official_name ?? undefined,
          mask: account.mask ?? undefined,
          type: account.type,
          subtype: account.subtype ?? undefined,
          availableBalance: account.balances.available?.toString(),
          currentBalance: account.balances.current?.toString(),
          creditLimit: account.balances.limit?.toString(),
          isoCurrencyCode: account.balances.iso_currency_code ?? undefined,
          balancesUpdatedAt: new Date(),
        })),
      );
    }

    return { itemId: itemRow.id, plaidItemId, accountCount: plaidAccounts.length };
  }

  async decryptAccessToken(itemDbId: string): Promise<string> {
    const [item] = await this.db
      .select({ accessTokenEncrypted: plaidItems.accessTokenEncrypted })
      .from(plaidItems)
      .where(eq(plaidItems.id, itemDbId))
      .limit(1);
    if (!item) {
      throw new Error(`Plaid item ${itemDbId} not found`);
    }
    return this.crypto.decrypt(item.accessTokenEncrypted);
  }

  async listItems() {
    return this.db
      .select({
        id: plaidItems.id,
        status: plaidItems.status,
        statusDetail: plaidItems.statusDetail,
        institutionName: institutions.name,
        lastSuccessfulSyncAt: plaidItems.lastSuccessfulSyncAt,
        lastAttemptedSyncAt: plaidItems.lastAttemptedSyncAt,
        createdAt: plaidItems.createdAt,
      })
      .from(plaidItems)
      .leftJoin(institutions, eq(institutions.id, plaidItems.institutionId))
      .orderBy(desc(plaidItems.createdAt));
  }

  /**
   * After the user completes Plaid Link in update mode, the existing
   * access_token remains valid — there is nothing to re-exchange, we just
   * clear the re-auth-needed status.
   */
  async markReauthComplete(itemDbId: string): Promise<void> {
    await this.db
      .update(plaidItems)
      .set({ status: 'active', statusDetail: null })
      .where(eq(plaidItems.id, itemDbId));
  }
}
