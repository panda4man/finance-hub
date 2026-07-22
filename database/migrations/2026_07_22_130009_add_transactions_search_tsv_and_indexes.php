<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement("ALTER TABLE transactions ADD COLUMN search_tsv tsvector GENERATED ALWAYS AS (to_tsvector('english', coalesce(merchant_name, name))) STORED");
        DB::statement('CREATE INDEX transactions_search_tsv_gin ON transactions USING gin (search_tsv)');
        DB::statement('CREATE INDEX transactions_account_date_idx ON transactions (account_id, date DESC)');
        DB::statement('CREATE INDEX transactions_connection_date_idx ON transactions (connection_id, date DESC)');
        DB::statement('CREATE INDEX transactions_date_idx ON transactions (date DESC)');
        DB::statement('CREATE INDEX transactions_visible_date_idx ON transactions (date DESC) WHERE removed_at IS NULL AND is_hidden = false');
        DB::statement('CREATE INDEX transactions_pending_idx ON transactions (pending) WHERE pending = true');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS transactions_pending_idx');
        DB::statement('DROP INDEX IF EXISTS transactions_visible_date_idx');
        DB::statement('DROP INDEX IF EXISTS transactions_date_idx');
        DB::statement('DROP INDEX IF EXISTS transactions_connection_date_idx');
        DB::statement('DROP INDEX IF EXISTS transactions_account_date_idx');
        DB::statement('DROP INDEX IF EXISTS transactions_search_tsv_gin');
        DB::statement('ALTER TABLE transactions DROP COLUMN IF EXISTS search_tsv');
    }
};
