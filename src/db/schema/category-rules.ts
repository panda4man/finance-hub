import { pgTable, uuid, text, integer, boolean, timestamp, index, uniqueIndex } from 'drizzle-orm/pg-core';
import { categories } from './categories';

export const categoryRuleMatchFieldValues = ['name'] as const;
export type CategoryRuleMatchField = (typeof categoryRuleMatchFieldValues)[number];

export const categoryRuleMatchTypeValues = ['substring'] as const;
export type CategoryRuleMatchType = (typeof categoryRuleMatchTypeValues)[number];

export const categoryRuleAmountSignValues = ['any', 'outflow', 'inflow'] as const;
export type CategoryRuleAmountSign = (typeof categoryRuleAmountSignValues)[number];

export const categoryRuleSourceValues = ['default', 'user'] as const;
export type CategoryRuleSource = (typeof categoryRuleSourceValues)[number];

export const categoryRules = pgTable(
  'category_rules',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    // matched case-insensitively as a substring against transactions.name
    pattern: text('pattern').notNull(),
    matchField: text('match_field').notNull().default('name'),
    matchType: text('match_type').notNull().default('substring'),
    // optionally gates the rule on the transaction's amount sign (canonical
    // convention: positive = outflow/expense, negative = inflow/income)
    amountSign: text('amount_sign').notNull().default('any'),
    categoryId: uuid('category_id')
      .notNull()
      .references(() => categories.id, { onDelete: 'cascade' }),
    // lower wins; rules are evaluated in ascending priority order, first match wins
    priority: integer('priority').notNull().default(100),
    source: text('source').notNull().default('user'),
    isActive: boolean('is_active').notNull().default(true),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    uniqueIndex('uq_category_rules_pattern_field').on(t.pattern, t.matchField),
    index('idx_category_rules_priority').on(t.priority),
    index('idx_category_rules_category').on(t.categoryId),
  ],
);
