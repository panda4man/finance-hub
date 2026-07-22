import { Inject, Injectable } from '@nestjs/common';
import { asc, eq } from 'drizzle-orm';
import { DB, Database } from '../db/db.module';
import { accounts, categories } from '../db/schema';

@Injectable()
export class ReferenceService {
  constructor(@Inject(DB) private readonly db: Database) {}

  async listAccounts() {
    return this.db
      .select({
        id: accounts.id,
        name: accounts.name,
        officialName: accounts.officialName,
        mask: accounts.mask,
        type: accounts.type,
        subtype: accounts.subtype,
      })
      .from(accounts)
      .orderBy(asc(accounts.name));
  }

  async listCategories() {
    return this.db
      .select({
        id: categories.id,
        slug: categories.slug,
        name: categories.name,
        kind: categories.kind,
      })
      .from(categories)
      .where(eq(categories.isActive, true))
      .orderBy(asc(categories.name));
  }
}
