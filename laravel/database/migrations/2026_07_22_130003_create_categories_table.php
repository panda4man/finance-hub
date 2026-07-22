<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('parent_id')->nullable();
            $table->text('slug')->unique();
            $table->text('name');
            $table->text('kind')->default('source_provided');
            $table->text('source_primary')->nullable();
            $table->text('source_detailed')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('parent_id');
        });

        // Added as a separate step (not inline in Schema::create) because the self-referencing
        // FK would otherwise be added before the primary key constraint on this same table,
        // which Postgres rejects ("no unique constraint matching given keys").
        Schema::table('categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('categories')->nullOnDelete();
        });

        DB::statement("ALTER TABLE categories ADD CONSTRAINT chk_categories_kind CHECK (kind IN ('source_provided','custom'))");
        DB::statement('CREATE UNIQUE INDEX categories_source_detailed_unique ON categories (source_detailed) WHERE source_detailed IS NOT NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
