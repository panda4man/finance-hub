import { pgTable, uuid, text, numeric, timestamp, index } from 'drizzle-orm/pg-core';
import { connections } from './connections';
import { institutions } from './institutions';

export const accounts = pgTable(
  'accounts',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    connectionId: uuid('connection_id')
      .notNull()
      .references(() => connections.id, { onDelete: 'cascade' }),
    institutionId: uuid('institution_id').references(() => institutions.id, {
      onDelete: 'set null',
    }),
    externalAccountId: text('external_account_id').notNull().unique(),
    name: text('name').notNull(),
    officialName: text('official_name'),
    mask: text('mask'),
    // nullable: not every provider classifies accounts (SimpleFin doesn't)
    type: text('type'),
    subtype: text('subtype'),
    availableBalance: numeric('available_balance', { precision: 14, scale: 2 }),
    currentBalance: numeric('current_balance', { precision: 14, scale: 2 }),
    creditLimit: numeric('credit_limit', { precision: 14, scale: 2 }),
    isoCurrencyCode: text('iso_currency_code'),
    balancesUpdatedAt: timestamp('balances_updated_at', { withTimezone: true }),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [index('idx_accounts_connection').on(t.connectionId)],
);
