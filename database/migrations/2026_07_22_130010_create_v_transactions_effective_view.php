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
        DB::statement(<<<'SQL'
            CREATE VIEW v_transactions_effective AS
            SELECT t.*,
                   coalesce(t.user_category_id, t.category_id) AS effective_category_id,
                   c.slug AS category_slug,
                   c.name AS category_name
            FROM transactions t
            LEFT JOIN categories c ON c.id = coalesce(t.user_category_id, t.category_id)
            WHERE t.removed_at IS NULL AND t.is_hidden = false
        SQL);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_transactions_effective');
    }
};
