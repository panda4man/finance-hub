import { pgTable, uuid, text, timestamp, index } from 'drizzle-orm/pg-core';
import { users } from './users';

export const connectionStatusValues = [
  'active',
  'login_required',
  'pending_expiration',
  'revoked',
  'error',
] as const;
export type ConnectionStatus = (typeof connectionStatusValues)[number];

/**
 * One claimed provider credential. A single credential can back multiple
 * institutions (e.g. a SimpleFin Access URL commonly aggregates several
 * banks) — see `accounts.institutionId`, not this table, for the
 * per-institution relationship.
 */
export const connections = pgTable(
  'connections',
  {
    id: uuid('id').primaryKey().defaultRandom(),
    userId: uuid('user_id')
      .notNull()
      .references(() => users.id, { onDelete: 'cascade' }),
    provider: text('provider').notNull(),
    credentialEncrypted: text('credential_encrypted'),
    syncCursor: text('sync_cursor'),
    status: text('status').notNull().default('active'),
    statusDetail: text('status_detail'),
    consentExpirationTime: timestamp('consent_expiration_time', { withTimezone: true }),
    lastSuccessfulSyncAt: timestamp('last_successful_sync_at', { withTimezone: true }),
    lastAttemptedSyncAt: timestamp('last_attempted_sync_at', { withTimezone: true }),
    createdAt: timestamp('created_at', { withTimezone: true }).notNull().defaultNow(),
    updatedAt: timestamp('updated_at', { withTimezone: true }).notNull().defaultNow(),
  },
  (t) => [index('idx_connections_user').on(t.userId), index('idx_connections_status').on(t.status)],
);
