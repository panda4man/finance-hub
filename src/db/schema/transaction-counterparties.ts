import { pgTable, uuid, text, index } from 'drizzle-orm/pg-core';
import { transactions } from './transactions';

export const transactionCounterparties = pgTable(
  'transaction_counterparties',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    transactionId: uuid('transaction_id')
      .notNull()
      .references(() => transactions.id, { onDelete: 'cascade' }),
    name: text('name').notNull(),
    type: text('type'),
    entityId: text('entity_id'),
    website: text('website'),
    logoUrl: text('logo_url'),
    confidenceLevel: text('confidence_level'),
  },
  (t) => [index('idx_txn_cp_txn').on(t.transactionId)],
);
