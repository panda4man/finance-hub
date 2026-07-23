<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('import_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->text('name');
            $table->json('column_mapping');
            $table->text('date_format');
            $table->boolean('flip_amount_sign')->default(false);
            $table->text('dedupe_strategy')->default('composite');
            $table->json('dedupe_columns')->nullable();
            $table->json('header_signature');
            $table->boolean('is_seeded')->default(false);
            $table->timestampsTz();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_templates');
    }
};
