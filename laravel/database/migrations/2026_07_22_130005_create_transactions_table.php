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
        Schema::create('transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('connection_id')->constrained()->cascadeOnDelete();
            $table->text('external_transaction_id')->unique();
            $table->boolean('pending')->default(false);
            $table->text('pending_external_transaction_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->text('iso_currency_code')->nullable();
            $table->text('unofficial_currency_code')->nullable();
            $table->date('date');
            $table->date('authorized_date')->nullable();
            $table->timestampTz('datetime')->nullable();
            $table->timestampTz('authorized_datetime')->nullable();
            $table->text('name');
            $table->text('merchant_name')->nullable();
            $table->text('merchant_entity_id')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('website')->nullable();
            $table->text('payment_channel')->nullable();
            $table->text('source_category_primary')->nullable();
            $table->text('source_category_detailed')->nullable();
            $table->text('source_category_confidence')->nullable();
            $table->foreignUuid('category_id')->nullable()->constrained();
            $table->foreignUuid('user_category_id')->nullable()->constrained('categories');
            $table->text('user_notes')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->text('location_city')->nullable();
            $table->text('location_region')->nullable();
            $table->text('location_country')->nullable();
            $table->text('location_postal_code')->nullable();
            $table->decimal('location_lat', 9, 6)->nullable();
            $table->decimal('location_lon', 9, 6)->nullable();
            $table->timestampTz('removed_at')->nullable();
            $table->jsonb('raw_payload');
            $table->timestampTz('first_seen_at')->default(DB::raw('now()'));
            $table->timestampTz('last_modified_at')->default(DB::raw('now()'));
            $table->timestampTz('created_at')->nullable()->default(DB::raw('now()'));
            $table->timestampTz('updated_at')->nullable()->default(DB::raw('now()'));

            $table->index('category_id');
            $table->index('user_category_id');
            $table->index('merchant_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
