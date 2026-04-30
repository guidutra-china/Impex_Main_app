<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->boolean('eta_change_pending_recalc')
                ->default(false)
                ->after('eta')
                ->index();
            $table->timestamp('eta_changed_at')
                ->nullable()
                ->after('eta_change_pending_recalc');
            $table->foreignId('eta_changed_by')
                ->nullable()
                ->after('eta_changed_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['eta_changed_by']);
            $table->dropColumn(['eta_change_pending_recalc', 'eta_changed_at', 'eta_changed_by']);
        });
    }
};
