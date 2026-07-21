-- Replace Plaid with a provider-agnostic schema. Clean cutover: all
-- Plaid-sourced connection/account/transaction data is truncated (Plaid's
-- IDs can never match a future provider's anyway). The category taxonomy
-- (seed data) is kept, just relabeled off "plaid_pfc".
TRUNCATE TABLE "transaction_counterparties", "transactions", "sync_runs", "accounts", "plaid_items", "institutions" RESTART IDENTITY CASCADE;
--> statement-breakpoint

-- institutions: drop vendor-specific id column name, scope uniqueness by provider
ALTER TABLE "institutions" DROP CONSTRAINT "institutions_plaid_institution_id_unique";
--> statement-breakpoint
ALTER TABLE "institutions" RENAME COLUMN "plaid_institution_id" TO "external_org_id";
--> statement-breakpoint
ALTER TABLE "institutions" ADD COLUMN "provider" text NOT NULL DEFAULT '';
--> statement-breakpoint
ALTER TABLE "institutions" ALTER COLUMN "provider" DROP DEFAULT;
--> statement-breakpoint
CREATE UNIQUE INDEX "institutions_provider_external_org_id_unique" ON "institutions" USING btree ("provider","external_org_id");
--> statement-breakpoint

-- plaid_items -> connections: one claimed credential, not one institution
-- (a single SimpleFin Access URL commonly spans multiple institutions, so
-- institution now lives on accounts instead — see below)
ALTER TABLE "plaid_items" RENAME TO "connections";
--> statement-breakpoint
ALTER TABLE "connections" RENAME CONSTRAINT "plaid_items_pkey" TO "connections_pkey";
--> statement-breakpoint
ALTER TABLE "connections" RENAME CONSTRAINT "plaid_items_user_id_users_id_fk" TO "connections_user_id_users_id_fk";
--> statement-breakpoint
ALTER TABLE "connections" DROP CONSTRAINT "plaid_items_institution_id_institutions_id_fk";
--> statement-breakpoint
ALTER TABLE "connections" DROP COLUMN "institution_id";
--> statement-breakpoint
ALTER TABLE "connections" DROP CONSTRAINT "plaid_items_plaid_item_id_unique";
--> statement-breakpoint
ALTER TABLE "connections" DROP COLUMN "plaid_item_id";
--> statement-breakpoint
ALTER TABLE "connections" ADD COLUMN "provider" text NOT NULL DEFAULT '';
--> statement-breakpoint
ALTER TABLE "connections" ALTER COLUMN "provider" DROP DEFAULT;
--> statement-breakpoint
ALTER TABLE "connections" RENAME COLUMN "access_token_encrypted" TO "credential_encrypted";
--> statement-breakpoint
ALTER TABLE "connections" ALTER COLUMN "credential_encrypted" DROP NOT NULL;
--> statement-breakpoint
ALTER TABLE "connections" RENAME COLUMN "transactions_cursor" TO "sync_cursor";
--> statement-breakpoint
ALTER INDEX "idx_plaid_items_user" RENAME TO "idx_connections_user";
--> statement-breakpoint
ALTER INDEX "idx_plaid_items_status" RENAME TO "idx_connections_status";
--> statement-breakpoint

-- accounts: connection_id (renamed) + new nullable institution_id
ALTER TABLE "accounts" RENAME COLUMN "item_id" TO "connection_id";
--> statement-breakpoint
ALTER TABLE "accounts" RENAME CONSTRAINT "accounts_item_id_plaid_items_id_fk" TO "accounts_connection_id_connections_id_fk";
--> statement-breakpoint
ALTER TABLE "accounts" ADD COLUMN "institution_id" uuid;
--> statement-breakpoint
ALTER TABLE "accounts" ADD CONSTRAINT "accounts_institution_id_institutions_id_fk" FOREIGN KEY ("institution_id") REFERENCES "public"."institutions"("id") ON DELETE set null ON UPDATE no action;
--> statement-breakpoint
ALTER TABLE "accounts" RENAME COLUMN "plaid_account_id" TO "external_account_id";
--> statement-breakpoint
ALTER TABLE "accounts" RENAME CONSTRAINT "accounts_plaid_account_id_unique" TO "accounts_external_account_id_unique";
--> statement-breakpoint
ALTER INDEX "idx_accounts_item" RENAME TO "idx_accounts_connection";
--> statement-breakpoint
-- not every provider classifies accounts (SimpleFin doesn't)
ALTER TABLE "accounts" ALTER COLUMN "type" DROP NOT NULL;
--> statement-breakpoint

-- categories: relabel taxonomy fields, keep seeded data
ALTER TABLE "categories" RENAME COLUMN "plaid_pfc_primary" TO "source_primary";
--> statement-breakpoint
ALTER TABLE "categories" RENAME COLUMN "plaid_pfc_detailed" TO "source_detailed";
--> statement-breakpoint
UPDATE "categories" SET "kind" = 'source_provided' WHERE "kind" = 'plaid_pfc';
--> statement-breakpoint
ALTER TABLE "categories" ALTER COLUMN "kind" SET DEFAULT 'source_provided';
--> statement-breakpoint
DROP INDEX "uq_categories_pfc_detailed";
--> statement-breakpoint
CREATE UNIQUE INDEX "uq_categories_source_detailed" ON "categories" USING btree ("source_detailed") WHERE "categories"."source_detailed" is not null;
--> statement-breakpoint

-- transactions: connection_id (renamed), external ids, drop Plaid-v1-only
-- legacy category columns, relabel PFC-style fields as generic source-category fields
ALTER TABLE "transactions" RENAME COLUMN "item_id" TO "connection_id";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME CONSTRAINT "transactions_item_id_plaid_items_id_fk" TO "transactions_connection_id_connections_id_fk";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME COLUMN "plaid_transaction_id" TO "external_transaction_id";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME CONSTRAINT "transactions_plaid_transaction_id_unique" TO "transactions_external_transaction_id_unique";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME COLUMN "pending_plaid_transaction_id" TO "pending_external_transaction_id";
--> statement-breakpoint
ALTER TABLE "transactions" DROP COLUMN "plaid_category_legacy";
--> statement-breakpoint
ALTER TABLE "transactions" DROP COLUMN "plaid_category_id_legacy";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME COLUMN "plaid_pfc_primary" TO "source_category_primary";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME COLUMN "plaid_pfc_detailed" TO "source_category_detailed";
--> statement-breakpoint
ALTER TABLE "transactions" RENAME COLUMN "plaid_pfc_confidence" TO "source_category_confidence";
--> statement-breakpoint
ALTER INDEX "idx_txn_item_date" RENAME TO "idx_txn_connection_date";
--> statement-breakpoint

-- sync_runs: connection_id (renamed)
ALTER TABLE "sync_runs" RENAME COLUMN "item_id" TO "connection_id";
--> statement-breakpoint
ALTER TABLE "sync_runs" RENAME CONSTRAINT "sync_runs_item_id_plaid_items_id_fk" TO "sync_runs_connection_id_connections_id_fk";
--> statement-breakpoint
ALTER INDEX "idx_sync_runs_item_started" RENAME TO "idx_sync_runs_connection_started";
