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
        Schema::create('institutions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('provider');
            $table->text('external_org_id');
            $table->text('name');
            $table->text('url')->nullable();
            $table->text('primary_color')->nullable();
            $table->text('logo_base64')->nullable();
            $table->timestampsTz();

            $table->unique(['provider', 'external_org_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('institutions');
    }
};
