import { Inject, Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { eq } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { appSettings } from '../db/schema';
import { CryptoService } from '../crypto/crypto.service';
import type { Env } from '../config/env.validation';

const PLAID_KEYS = {
  clientId: 'plaid_client_id',
  secret: 'plaid_secret',
  env: 'plaid_env',
  redirectUri: 'plaid_redirect_uri',
} as const;

export interface PlaidCredentials {
  clientId: string;
  secret: string;
  env: string;
  redirectUri?: string;
}

@Injectable()
export class SettingsService {
  constructor(
    @Inject(DB) private readonly db: Database,
    private readonly crypto: CryptoService,
    private readonly config: ConfigService<Env, true>,
  ) {}

  /** Raw get/set — value is encrypted at rest, decrypted on read. Returns undefined if never set. */
  async get(key: string): Promise<string | undefined> {
    const [row] = await this.db
      .select({ valueEncrypted: appSettings.valueEncrypted })
      .from(appSettings)
      .where(eq(appSettings.key, key))
      .limit(1);
    return row ? this.crypto.decrypt(row.valueEncrypted) : undefined;
  }

  async set(key: string, value: string): Promise<void> {
    await this.db
      .insert(appSettings)
      .values({ key, valueEncrypted: this.crypto.encrypt(value) })
      .onConflictDoUpdate({
        target: appSettings.key,
        set: { valueEncrypted: this.crypto.encrypt(value), updatedAt: new Date() },
      });
  }

  /** DB value wins if set; otherwise falls back to the env var configured at boot. */
  async getPlaidCredentials(): Promise<PlaidCredentials> {
    const [clientId, secret, env, redirectUri] = await Promise.all([
      this.get(PLAID_KEYS.clientId),
      this.get(PLAID_KEYS.secret),
      this.get(PLAID_KEYS.env),
      this.get(PLAID_KEYS.redirectUri),
    ]);

    return {
      clientId: clientId ?? this.config.get<string>('PLAID_CLIENT_ID'),
      secret: secret ?? this.config.get<string>('PLAID_SECRET'),
      env: env ?? this.config.get<string>('PLAID_ENV'),
      redirectUri:
        redirectUri || this.config.get<string | undefined>('PLAID_REDIRECT_URI') || undefined,
    };
  }

  /** Empty/blank fields are treated as "leave unchanged" — the setup form never round-trips the real secret. */
  async setPlaidCredentials(input: {
    clientId?: string;
    secret?: string;
    env?: string;
    redirectUri?: string;
  }): Promise<void> {
    if (input.clientId) await this.set(PLAID_KEYS.clientId, input.clientId);
    if (input.secret) await this.set(PLAID_KEYS.secret, input.secret);
    if (input.env) await this.set(PLAID_KEYS.env, input.env);
    if (input.redirectUri) {
      await this.set(PLAID_KEYS.redirectUri, input.redirectUri);
    }
  }

  /** For display in the setup form — secret is masked, everything else shown plain. */
  async getPlaidCredentialsMasked(): Promise<{
    clientId: string;
    secretMasked: string;
    env: string;
    redirectUri?: string;
  }> {
    const creds = await this.getPlaidCredentials();
    const secretMasked = creds.secret
      ? `${'•'.repeat(Math.max(creds.secret.length - 4, 0))}${creds.secret.slice(-4)}`
      : '';
    return { clientId: creds.clientId, secretMasked, env: creds.env, redirectUri: creds.redirectUri };
  }
}
