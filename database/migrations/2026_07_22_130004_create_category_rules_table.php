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
        Schema::create('category_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('pattern');
            $table->text('match_field')->default('name');
            $table->text('match_type')->default('substring');
            $table->text('amount_sign')->default('any');
            $table->foreignUuid('category_id')->constrained()->cascadeOnDelete();
            $table->integer('priority')->default(100);
            $table->text('source')->default('user');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['pattern', 'match_field']);
            $table->index('priority');
            $table->index('category_id');
        });

        DB::statement("ALTER TABLE category_rules ADD CONSTRAINT chk_category_rules_match_field CHECK (match_field IN ('name'))");
        DB::statement("ALTER TABLE category_rules ADD CONSTRAINT chk_category_rules_match_type CHECK (match_type IN ('substring'))");
        DB::statement("ALTER TABLE category_rules ADD CONSTRAINT chk_category_rules_amount_sign CHECK (amount_sign IN ('any','outflow','inflow'))");
        DB::statement("ALTER TABLE category_rules ADD CONSTRAINT chk_category_rules_source CHECK (source IN ('default','user'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category_rules');
    }
};
