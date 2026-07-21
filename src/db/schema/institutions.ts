import { pgTable, uuid, text, timestamp } from 'drizzle-orm/pg-core';

export const institutions = pgTable('institutions', {
  id: uuid('id').primaryKey().defaultRandom(),
  plaidInstitutionId: text('plaid_institution_id').notNull().unique(),
  name: text('name').notNull(),
  url: text('url'),
  primaryColor: text('primary_color'),
  logoBase64: text('logo_base64'),
  createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
});
