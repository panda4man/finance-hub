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
        Schema::create('import_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('connection_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('account_id')->nullable()->constrained()->nullOnDelete();
            $table->text('status');
            $table->text('file_name');
            $table->text('file_path')->nullable();
            $table->integer('row_count')->default(0);
            $table->integer('added_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->timestampTz('started_at')->default(DB::raw('now()'));
            $table->timestampTz('finished_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('created_at')->nullable()->default(DB::raw('now()'));

            $table->index(['connection_id', 'started_at']);
            $table->index(['account_id', 'started_at']);
            $table->index('status');
        });

        DB::statement("ALTER TABLE import_runs ADD CONSTRAINT chk_import_runs_status CHECK (status IN ('running','success','partial','failed'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_runs');
    }
};
