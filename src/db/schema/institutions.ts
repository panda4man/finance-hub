import { pgTable, uuid, text, timestamp, uniqueIndex } from 'drizzle-orm/pg-core';

export const institutions = pgTable(
  'institutions',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    provider: text('provider').notNull(),
    externalOrgId: text('external_org_id').notNull(),
    name: text('name').notNull(),
    url: text('url'),
    primaryColor: text('primary_color'),
    logoBase64: text('logo_base64'),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [uniqueIndex('institutions_provider_external_org_id_unique').on(t.provider, t.externalOrgId)],
);
