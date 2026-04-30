<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->string('forwarder_status', 32)
                ->nullable()
                ->after('status')
                ->index();
        });

        // Backfill: rows that already carry a forwarder_amount inherit the
        // current status as the starting point for the forwarder side, so
        // legacy data doesn't all switch to "—".
        DB::table('additional_costs')
            ->whereNotNull('forwarder_amount')
            ->update(['forwarder_status' => DB::raw('status')]);
    }

    public function down(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->dropColumn('forwarder_status');
        });
    }
};
