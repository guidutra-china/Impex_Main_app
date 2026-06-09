<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Idempotency key from the offline fair PWA: lets a company captured
            // on a device be re-synced without creating duplicates. Null for
            // companies created elsewhere (admin, other modules).
            $table->uuid('client_uuid')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['client_uuid']);
            $table->dropColumn('client_uuid');
        });
    }
};
