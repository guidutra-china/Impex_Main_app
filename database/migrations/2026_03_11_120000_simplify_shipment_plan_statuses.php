<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // pending_payment and ready_to_ship are now just "confirmed"
        DB::table('shipment_plans')
            ->whereIn('status', ['pending_payment', 'ready_to_ship'])
            ->update(['status' => 'confirmed']);
    }

    public function down(): void
    {
        // Cannot reliably revert — no data loss, just status simplification
    }
};
