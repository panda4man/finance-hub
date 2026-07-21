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

export const categoryKindValues = ['source_provided', 'custom'] as const;
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
    kind: text('kind').notNull().default('source_provided'),
    sourcePrimary: text('source_primary'),
    sourceDetailed: text('source_detailed'),
    isActive: boolean('is_active').notNull().default(true),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    index('idx_categories_parent').on(t.parentId),
    uniqueIndex('uq_categories_source_detailed')
      .on(t.sourceDetailed)
      .where(sql`${t.sourceDetailed} is not null`),
  ],
);
