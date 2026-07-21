CREATE TABLE "users" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"email" text,
	"display_name" text,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "users_email_unique" UNIQUE("email")
);
--> statement-breakpoint
CREATE TABLE "institutions" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"plaid_institution_id" text NOT NULL,
	"name" text NOT NULL,
	"url" text,
	"primary_color" text,
	"logo_base64" text,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "institutions_plaid_institution_id_unique" UNIQUE("plaid_institution_id")
);
--> statement-breakpoint
CREATE TABLE "plaid_items" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"user_id" uuid NOT NULL,
	"institution_id" uuid,
	"plaid_item_id" text NOT NULL,
	"access_token_encrypted" text NOT NULL,
	"transactions_cursor" text,
	"status" text DEFAULT 'active' NOT NULL,
	"status_detail" text,
	"consent_expiration_time" timestamp with time zone,
	"last_successful_sync_at" timestamp with time zone,
	"last_attempted_sync_at" timestamp with time zone,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "plaid_items_plaid_item_id_unique" UNIQUE("plaid_item_id")
);
--> statement-breakpoint
CREATE TABLE "accounts" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"item_id" uuid NOT NULL,
	"plaid_account_id" text NOT NULL,
	"name" text NOT NULL,
	"official_name" text,
	"mask" text,
	"type" text NOT NULL,
	"subtype" text,
	"available_balance" numeric(14, 2),
	"current_balance" numeric(14, 2),
	"credit_limit" numeric(14, 2),
	"iso_currency_code" text,
	"balances_updated_at" timestamp with time zone,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "accounts_plaid_account_id_unique" UNIQUE("plaid_account_id")
);
--> statement-breakpoint
CREATE TABLE "categories" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"parent_id" uuid,
	"slug" text NOT NULL,
	"name" text NOT NULL,
	"kind" text DEFAULT 'plaid_pfc' NOT NULL,
	"plaid_pfc_primary" text,
	"plaid_pfc_detailed" text,
	"is_active" boolean DEFAULT true NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "categories_slug_unique" UNIQUE("slug")
);
--> statement-breakpoint
CREATE TABLE "transactions" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"account_id" uuid NOT NULL,
	"item_id" uuid NOT NULL,
	"plaid_transaction_id" text NOT NULL,
	"pending" boolean DEFAULT false NOT NULL,
	"pending_plaid_transaction_id" text,
	"amount" numeric(14, 2) NOT NULL,
	"iso_currency_code" text,
	"unofficial_currency_code" text,
	"date" date NOT NULL,
	"authorized_date" date,
	"datetime" timestamp with time zone,
	"authorized_datetime" timestamp with time zone,
	"name" text NOT NULL,
	"merchant_name" text,
	"merchant_entity_id" text,
	"logo_url" text,
	"website" text,
	"payment_channel" text,
	"plaid_category_legacy" text[],
	"plaid_category_id_legacy" text,
	"plaid_pfc_primary" text,
	"plaid_pfc_detailed" text,
	"plaid_pfc_confidence" text,
	"category_id" uuid,
	"user_category_id" uuid,
	"user_notes" text,
	"is_hidden" boolean DEFAULT false NOT NULL,
	"location_city" text,
	"location_region" text,
	"location_country" text,
	"location_postal_code" text,
	"location_lat" numeric(9, 6),
	"location_lon" numeric(9, 6),
	"removed_at" timestamp with time zone,
	"search_tsv" "tsvector" GENERATED ALWAYS AS (to_tsvector('english', coalesce(merchant_name, name))) STORED,
	"raw_payload" jsonb NOT NULL,
	"first_seen_at" timestamp with time zone DEFAULT now() NOT NULL,
	"last_modified_at" timestamp with time zone DEFAULT now() NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL,
	CONSTRAINT "transactions_plaid_transaction_id_unique" UNIQUE("plaid_transaction_id")
);
--> statement-breakpoint
CREATE TABLE "transaction_counterparties" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"transaction_id" uuid NOT NULL,
	"name" text NOT NULL,
	"type" text,
	"entity_id" text,
	"website" text,
	"logo_url" text,
	"confidence_level" text
);
--> statement-breakpoint
CREATE TABLE "sync_runs" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"item_id" uuid,
	"trigger" text NOT NULL,
	"status" text NOT NULL,
	"started_at" timestamp with time zone DEFAULT now() NOT NULL,
	"finished_at" timestamp with time zone,
	"cursor_before" text,
	"cursor_after" text,
	"pages_fetched" integer DEFAULT 0 NOT NULL,
	"added_count" integer DEFAULT 0 NOT NULL,
	"modified_count" integer DEFAULT 0 NOT NULL,
	"removed_count" integer DEFAULT 0 NOT NULL,
	"accounts_upserted" integer DEFAULT 0 NOT NULL,
	"error_code" text,
	"error_message" text,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
ALTER TABLE "plaid_items" ADD CONSTRAINT "plaid_items_user_id_users_id_fk" FOREIGN KEY ("user_id") REFERENCES "public"."users"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "plaid_items" ADD CONSTRAINT "plaid_items_institution_id_institutions_id_fk" FOREIGN KEY ("institution_id") REFERENCES "public"."institutions"("id") ON DELETE no action ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "accounts" ADD CONSTRAINT "accounts_item_id_plaid_items_id_fk" FOREIGN KEY ("item_id") REFERENCES "public"."plaid_items"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "categories" ADD CONSTRAINT "categories_parent_id_categories_id_fk" FOREIGN KEY ("parent_id") REFERENCES "public"."categories"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "transactions" ADD CONSTRAINT "transactions_account_id_accounts_id_fk" FOREIGN KEY ("account_id") REFERENCES "public"."accounts"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "transactions" ADD CONSTRAINT "transactions_item_id_plaid_items_id_fk" FOREIGN KEY ("item_id") REFERENCES "public"."plaid_items"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "transactions" ADD CONSTRAINT "transactions_category_id_categories_id_fk" FOREIGN KEY ("category_id") REFERENCES "public"."categories"("id") ON DELETE no action ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "transactions" ADD CONSTRAINT "transactions_user_category_id_categories_id_fk" FOREIGN KEY ("user_category_id") REFERENCES "public"."categories"("id") ON DELETE no action ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "transaction_counterparties" ADD CONSTRAINT "transaction_counterparties_transaction_id_transactions_id_fk" FOREIGN KEY ("transaction_id") REFERENCES "public"."transactions"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
ALTER TABLE "sync_runs" ADD CONSTRAINT "sync_runs_item_id_plaid_items_id_fk" FOREIGN KEY ("item_id") REFERENCES "public"."plaid_items"("id") ON DELETE set null ON UPDATE no action;--> statement-breakpoint
CREATE INDEX "idx_plaid_items_user" ON "plaid_items" USING btree ("user_id");--> statement-breakpoint
CREATE INDEX "idx_plaid_items_status" ON "plaid_items" USING btree ("status");--> statement-breakpoint
CREATE INDEX "idx_accounts_item" ON "accounts" USING btree ("item_id");--> statement-breakpoint
CREATE INDEX "idx_categories_parent" ON "categories" USING btree ("parent_id");--> statement-breakpoint
CREATE UNIQUE INDEX "uq_categories_pfc_detailed" ON "categories" USING btree ("plaid_pfc_detailed") WHERE "categories"."plaid_pfc_detailed" is not null;--> statement-breakpoint
CREATE INDEX "idx_txn_account_date" ON "transactions" USING btree ("account_id","date" desc);--> statement-breakpoint
CREATE INDEX "idx_txn_item_date" ON "transactions" USING btree ("item_id","date" desc);--> statement-breakpoint
CREATE INDEX "idx_txn_date" ON "transactions" USING btree ("date" desc);--> statement-breakpoint
CREATE INDEX "idx_txn_category" ON "transactions" USING btree ("category_id");--> statement-breakpoint
CREATE INDEX "idx_txn_user_category" ON "transactions" USING btree ("user_category_id");--> statement-breakpoint
CREATE INDEX "idx_txn_merchant" ON "transactions" USING btree ("merchant_name");--> statement-breakpoint
CREATE INDEX "idx_txn_search" ON "transactions" USING gin ("search_tsv");--> statement-breakpoint
CREATE INDEX "idx_txn_active_date" ON "transactions" USING btree ("date" desc) WHERE "transactions"."removed_at" is null and "transactions"."is_hidden" = false;--> statement-breakpoint
CREATE INDEX "idx_txn_pending" ON "transactions" USING btree ("pending") WHERE "transactions"."pending" = true;--> statement-breakpoint
CREATE INDEX "idx_txn_cp_txn" ON "transaction_counterparties" USING btree ("transaction_id");--> statement-breakpoint
CREATE INDEX "idx_sync_runs_item_started" ON "sync_runs" USING btree ("item_id","started_at" desc);--> statement-breakpoint
CREATE INDEX "idx_sync_runs_status" ON "sync_runs" USING btree ("status");