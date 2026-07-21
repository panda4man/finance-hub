import {
  pgTable,
  uuid,
  text,
  boolean,
  timestamp,
  index,
  uniqueIndex,
  type AnyPgColumn,
} from 'drizzle-orm/pg-core';
import { sql } from 'drizzle-orm';

export const categoryKindValues = ['plaid_pfc', 'custom'] as const;
export type CategoryKind = (typeof categoryKindValues)[number];

export const categories = pgTable(
  'categories',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    parentId: uuid('parent_id').references((): AnyPgColumn => categories.id, {
      onDelete: 'set null',
    }),
    slug: text('slug').notNull().unique(),
    name: text('name').notNull(),
    kind: text('kind').notNull().default('plaid_pfc'),
    plaidPfcPrimary: text('plaid_pfc_primary'),
    plaidPfcDetailed: text('plaid_pfc_detailed'),
    isActive: boolean('is_active').notNull().default(true),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    index('idx_categories_parent').on(t.parentId),
    uniqueIndex('uq_categories_pfc_detailed')
      .on(t.plaidPfcDetailed)
      .where(sql`${t.plaidPfcDetailed} is not null`),
  ],
);
