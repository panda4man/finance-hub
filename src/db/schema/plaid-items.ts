import { pgTable, uuid, text, timestamp, index } from 'drizzle-orm/pg-core';
import { users } from './users';
import { institutions } from './institutions';

export const plaidItemStatusValues = [
  'active',
  'login_required',
  'pending_expiration',
  'revoked',
  'error',
] as const;
export type PlaidItemStatus = (typeof plaidItemStatusValues)[number];

export const plaidItems = pgTable(
  'plaid_items',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    userId: uuid('user_id')
      .notNull()
      .references(() => users.id, { onDelete: 'cascade' }),
    institutionId: uuid('institution_id').references(() => institutions.id),
    plaidItemId: text('plaid_item_id').notNull().unique(),
    accessTokenEncrypted: text('access_token_encrypted').notNull(),
    transactionsCursor: text('transactions_cursor'),
    status: text('status').notNull().default('active'),
    statusDetail: text('status_detail'),
    consentExpirationTime: timestamp('consent_expiration_time', { withTimezone: true }),
    lastSuccessfulSyncAt: timestamp('last_successful_sync_at', { withTimezone: true }),
    lastAttemptedSyncAt: timestamp('last_attempted_sync_at', { withTimezone: true }),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [index('idx_plaid_items_user').on(t.userId), index('idx_plaid_items_status').on(t.status)],
);
