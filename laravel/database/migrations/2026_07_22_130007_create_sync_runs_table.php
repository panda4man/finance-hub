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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->text('trigger');
            $table->text('status');
            $table->timestampTz('started_at')->default(DB::raw('now()'));
            $table->timestampTz('finished_at')->nullable();
            $table->text('cursor_before')->nullable();
            $table->text('cursor_after')->nullable();
            $table->integer('pages_fetched')->default(0);
            $table->integer('added_count')->default(0);
            $table->integer('modified_count')->default(0);
            $table->integer('removed_count')->default(0);
            $table->integer('accounts_upserted')->default(0);
            $table->text('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->nullable()->default(DB::raw('now()'));

            $table->index(['connection_id', 'started_at']);
            $table->index('status');
        });

        DB::statement("ALTER TABLE sync_runs ADD CONSTRAINT chk_sync_runs_trigger CHECK (trigger IN ('scheduled','manual','webhook','backfill'))");
        DB::statement("ALTER TABLE sync_runs ADD CONSTRAINT chk_sync_runs_status CHECK (status IN ('running','success','partial','failed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
