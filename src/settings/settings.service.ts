import { Inject, Injectable } from '@nestjs/common';
import { eq } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { appSettings } from '../db/schema';
import { CryptoService } from '../crypto/crypto.service';

/** Generic encrypted key/value store for mutable app-level settings. */
@Injectable()
export class SettingsService {
  constructor(
    @Inject(DB) private readonly db: Database,
    private readonly crypto: CryptoService,
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
}
