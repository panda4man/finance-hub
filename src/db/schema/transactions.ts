import {
  pgTable,
  uuid,
  text,
  boolean,
  numeric,
  date,
  timestamp,
  jsonb,
  index,
} from 'drizzle-orm/pg-core';
import { sql } from 'drizzle-orm';
import { accounts } from './accounts';
import { plaidItems } from './plaid-items';
import { categories } from './categories';
import { tsvector } from './custom-types';

export const transactions = pgTable(
  'transactions',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    accountId: uuid('account_id')
      .notNull()
      .references(() => accounts.id, { onDelete: 'cascade' }),
    itemId: uuid('item_id')
      .notNull()
      .references(() => plaidItems.id, { onDelete: 'cascade' }),
    plaidTransactionId: text('plaid_transaction_id').notNull().unique(),
    pending: boolean('pending').notNull().default(false),
    pendingPlaidTransactionId: text('pending_plaid_transaction_id'),

    // money & timing
    amount: numeric('amount', { precision: 14, scale: 2 }).notNull(),
    isoCurrencyCode: text('iso_currency_code'),
    unofficialCurrencyCode: text('unofficial_currency_code'),
    date: date('date').notNull(),
    authorizedDate: date('authorized_date'),
    datetime: timestamp('datetime', { withTimezone: true }),
    authorizedDatetime: timestamp('authorized_datetime', { withTimezone: true }),

    // description / merchant
    name: text('name').notNull(),
    merchantName: text('merchant_name'),
    merchantEntityId: text('merchant_entity_id'),
    logoUrl: text('logo_url'),
    website: text('website'),
    paymentChannel: text('payment_channel'),

    // raw Plaid category data, preserved verbatim
    plaidCategoryLegacy: text('plaid_category_legacy').array(),
    plaidCategoryIdLegacy: text('plaid_category_id_legacy'),
    plaidPfcPrimary: text('plaid_pfc_primary'),
    plaidPfcDetailed: text('plaid_pfc_detailed'),
    plaidPfcConfidence: text('plaid_pfc_confidence'),

    // normalized category layer
    categoryId: uuid('category_id').references(() => categories.id),
    userCategoryId: uuid('user_category_id').references(() => categories.id),
    userNotes: text('user_notes'),
    isHidden: boolean('is_hidden').notNull().default(false),

    // geo
    locationCity: text('location_city'),
    locationRegion: text('location_region'),
    locationCountry: text('location_country'),
    locationPostalCode: text('location_postal_code'),
    locationLat: numeric('location_lat', { precision: 9, scale: 6 }),
    locationLon: numeric('location_lon', { precision: 9, scale: 6 }),

    // soft-delete for /transactions/sync "removed"
    removedAt: timestamp('removed_at', { withTimezone: true }),

    // full-text search hook; a pgvector embedding column can be added later
    // without reworking this table
    searchTsv: tsvector('search_tsv').generatedAlwaysAs(
      sql`to_tsvector('english', coalesce(merchant_name, name))`,
    ),

    rawPayload: jsonb('raw_payload').notNull(),
    firstSeenAt: timestamp('first_seen_at', { withTimezone: true }).notNull().defaultNow(),
    lastModifiedAt: timestamp('last_modified_at', { withTimezone: true }).notNull().defaultNow(),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    index('idx_txn_account_date').on(t.accountId, sql`${t.date} desc`),
    index('idx_txn_item_date').on(t.itemId, sql`${t.date} desc`),
    index('idx_txn_date').on(sql`${t.date} desc`),
    index('idx_txn_category').on(t.categoryId),
    index('idx_txn_user_category').on(t.userCategoryId),
    index('idx_txn_merchant').on(t.merchantName),
    index('idx_txn_search').using('gin', t.searchTsv),
    index('idx_txn_active_date')
      .on(sql`${t.date} desc`)
      .where(sql`${t.removedAt} is null and ${t.isHidden} = false`),
    index('idx_txn_pending').on(t.pending).where(sql`${t.pending} = true`),
  ],
);
