import { pgTable, uuid, text, integer, timestamp, index } from 'drizzle-orm/pg-core';
import { sql } from 'drizzle-orm';
import { connections } from './connections';

export const syncTriggerValues = ['scheduled', 'manual', 'webhook'] as const;
export type SyncTrigger = (typeof syncTriggerValues)[number];

export const syncRunStatusValues = ['running', 'success', 'partial', 'failed'] as const;
export type SyncRunStatus = (typeof syncRunStatusValues)[number];

export const syncRuns = pgTable(
  'sync_runs',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    connectionId: uuid('connection_id').references(() => connections.id, { onDelete: 'set null' }),
    trigger: text('trigger').notNull(),
    status: text('status').notNull(),
    startedAt: timestamp('started_at', { withTimezone: true }).notNull().defaultNow(),
    finishedAt: timestamp('finished_at', { withTimezone: true }),
    cursorBefore: text('cursor_before'),
    cursorAfter: text('cursor_after'),
    pagesFetched: integer('pages_fetched').notNull().default(0),
    addedCount: integer('added_count').notNull().default(0),
    modifiedCount: integer('modified_count').notNull().default(0),
    removedCount: integer('removed_count').notNull().default(0),
    accountsUpserted: integer('accounts_upserted').notNull().default(0),
    errorCode: text('error_code'),
    errorMessage: text('error_message'),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [
    index('idx_sync_runs_connection_started').on(t.connectionId, sql`${t.startedAt} desc`),
    index('idx_sync_runs_status').on(t.status),
  ],
);
