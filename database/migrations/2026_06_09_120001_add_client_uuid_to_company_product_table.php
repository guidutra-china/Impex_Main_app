<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            // Idempotency key for fair product lines captured offline: lets the
            // PWA re-sync edits to a product without duplicating the pivot.
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('company_product', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
