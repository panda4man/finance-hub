<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `type`/`subtype` are the raw, provider-specific values (only ever
     * populated by the legacy Plaid-shaped import — SimpleFin's protocol has
     * no equivalent field). `account_type` is the canonical, user-editable
     * classification the UI icon is driven from, backfilled here from
     * whatever legacy `type`/`subtype` data already exists.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->text('account_type')->nullable()->after('subtype');
        });

        DB::table('accounts')
            ->where('type', 'depository')
            ->where('subtype', 'checking')
            ->update(['account_type' => 'checking']);

        DB::table('accounts')
            ->where('type', 'depository')
            ->where('subtype', 'savings')
            ->update(['account_type' => 'savings']);

        DB::table('accounts')
            ->where('type', 'credit')
            ->update(['account_type' => 'credit_card']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn('account_type');
        });
    }
};
