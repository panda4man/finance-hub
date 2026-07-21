CREATE TABLE "category_rules" (
	"id" uuid PRIMARY KEY DEFAULT gen_random_uuid() NOT NULL,
	"pattern" text NOT NULL,
	"match_field" text DEFAULT 'name' NOT NULL,
	"match_type" text DEFAULT 'substring' NOT NULL,
	"amount_sign" text DEFAULT 'any' NOT NULL,
	"category_id" uuid NOT NULL,
	"priority" integer DEFAULT 100 NOT NULL,
	"source" text DEFAULT 'user' NOT NULL,
	"is_active" boolean DEFAULT true NOT NULL,
	"created_at" timestamp with time zone DEFAULT now() NOT NULL,
	"updated_at" timestamp with time zone DEFAULT now() NOT NULL
);
--> statement-breakpoint
DROP INDEX "idx_accounts_connection";--> statement-breakpoint
ALTER TABLE "category_rules" ADD CONSTRAINT "category_rules_category_id_categories_id_fk" FOREIGN KEY ("category_id") REFERENCES "public"."categories"("id") ON DELETE cascade ON UPDATE no action;--> statement-breakpoint
CREATE UNIQUE INDEX "uq_category_rules_pattern_field" ON "category_rules" USING btree ("pattern","match_field");--> statement-breakpoint
CREATE INDEX "idx_category_rules_priority" ON "category_rules" USING btree ("priority");--> statement-breakpoint
CREATE INDEX "idx_category_rules_category" ON "category_rules" USING btree ("category_id");--> statement-breakpoint
CREATE INDEX "idx_accounts_connection" ON "accounts" USING btree ("connection_id");