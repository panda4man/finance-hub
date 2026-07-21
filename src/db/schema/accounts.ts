import { pgTable, uuid, text, numeric, timestamp, index } from 'drizzle-orm/pg-core';
import { plaidItems } from './plaid-items';

export const accounts = pgTable(
  'accounts',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    itemId: uuid('item_id')
      .notNull()
      .references(() => plaidItems.id, { onDelete: 'cascade' }),
    plaidAccountId: text('plaid_account_id').notNull().unique(),
    name: text('name').notNull(),
    officialName: text('official_name'),
    mask: text('mask'),
    type: text('type').notNull(),
    subtype: text('subtype'),
    availableBalance: numeric('available_balance', { precision: 14, scale: 2 }),
    currentBalance: numeric('current_balance', { precision: 14, scale: 2 }),
    creditLimit: numeric('credit_limit', { precision: 14, scale: 2 }),
    isoCurrencyCode: text('iso_currency_code'),
    balancesUpdatedAt: timestamp('balances_updated_at', { withTimezone: true }),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [index('idx_accounts_item').on(t.itemId)],
);
