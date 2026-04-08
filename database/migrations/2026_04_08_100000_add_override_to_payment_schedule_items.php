<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->foreignId('overridden_by')
                ->nullable()
                ->after('waived_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('overridden_at')->nullable()->after('overridden_by');
            $table->text('override_reason')->nullable()->after('overridden_at');
        });
    }

    public function down(): void
    {
        Schema::table('payment_schedule_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['overridden_at', 'override_reason']);
        });
    }
};
