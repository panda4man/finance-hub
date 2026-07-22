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
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('institution_id')->nullable()->constrained()->nullOnDelete();
            $table->text('external_account_id')->unique();
            $table->text('name');
            $table->text('official_name')->nullable();
            $table->text('mask')->nullable();
            $table->text('type')->nullable();
            $table->text('subtype')->nullable();
            $table->decimal('available_balance', 14, 2)->nullable();
            $table->decimal('current_balance', 14, 2)->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable();
            $table->text('iso_currency_code')->nullable();
            $table->timestampTz('balances_updated_at')->nullable();
            $table->timestampsTz();

            $table->index('connection_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
