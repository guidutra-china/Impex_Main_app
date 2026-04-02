<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->nullable()->after('cost_type')
                ->comment('Commission percentage rate');
            $table->string('commission_mode', 20)->nullable()->after('commission_rate')
                ->comment('embedded = built into unit_price, separate = standalone payment schedule');
        });
    }

    public function down(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'commission_mode']);
        });
    }
};
