<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->foreignId('forwarder_company_id')
                ->nullable()
                ->after('freight_forwarder')
                ->constrained('companies')
                ->nullOnDelete();
        });

        // Migrate existing freight_forwarder text values to notes if needed
        // The old string column is kept for now to avoid data loss

        Schema::table('additional_costs', function (Blueprint $table) {
            $table->foreignId('forwarder_company_id')
                ->nullable()
                ->after('supplier_company_id')
                ->constrained('companies')
                ->nullOnDelete();
            $table->bigInteger('forwarder_amount')
                ->nullable()
                ->after('forwarder_company_id')
                ->comment('Amount payable to forwarder, in minor units (scale 10000)');
            $table->string('forwarder_currency_code', 10)
                ->nullable()
                ->after('forwarder_amount');
            $table->decimal('forwarder_exchange_rate', 15, 8)
                ->nullable()
                ->after('forwarder_currency_code')
                ->comment('Rate to convert forwarder amount to document currency');
            $table->bigInteger('forwarder_amount_in_document_currency')
                ->nullable()
                ->after('forwarder_exchange_rate')
                ->comment('Forwarder amount in document currency, minor units (scale 10000)');
        });
    }

    public function down(): void
    {
        Schema::table('additional_costs', function (Blueprint $table) {
            $table->dropForeign(['forwarder_company_id']);
            $table->dropColumn([
                'forwarder_company_id',
                'forwarder_amount',
                'forwarder_currency_code',
                'forwarder_exchange_rate',
                'forwarder_amount_in_document_currency',
            ]);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign(['forwarder_company_id']);
            $table->dropColumn('forwarder_company_id');
        });
    }
};
