<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debit_notes', function (Blueprint $table) {
            // A debit note can originate from an approved travel trip (mirrors
            // the existing proforma_invoice_id / shipment_id origin links).
            $table->foreignId('trip_id')
                ->nullable()
                ->after('shipment_id')
                ->constrained('trips')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debit_notes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('trip_id');
        });
    }
};
