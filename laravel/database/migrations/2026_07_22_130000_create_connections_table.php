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
        Schema::create('connections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('provider');
            $table->text('credential_encrypted')->nullable();
            $table->text('sync_cursor')->nullable();
            $table->text('status')->default('active');
            $table->text('status_detail')->nullable();
            $table->timestampTz('consent_expiration_time')->nullable();
            $table->timestampTz('last_successful_sync_at')->nullable();
            $table->timestampTz('last_attempted_sync_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
            $table->index('status');
        });

        DB::statement("ALTER TABLE connections ADD CONSTRAINT chk_connections_status CHECK (status IN ('active','login_required','pending_expiration','revoked','error'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};
