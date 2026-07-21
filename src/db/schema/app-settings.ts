import { pgTable, text, timestamp } from 'drizzle-orm/pg-core';

/** Generic encrypted key/value store for mutable app-level secrets (e.g. Plaid credentials). */
export const appSettings = pgTable('app_settings', {
  key: text('key').primaryKey(),
  valueEncrypted: text('value_encrypted').notNull(),
  updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
});
