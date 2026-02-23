<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('additional_costs', function (Blueprint $table) {
            $table->id();
            $table->morphs('costable');
            $table->string('cost_type', 50);
            $table->string('description', 255);
            $table->bigInteger('amount')->default(0)->comment('In minor units (scale 10000)');
            $table->string('currency_code', 10);
            $table->decimal('exchange_rate', 15, 8)->nullable()->comment('Rate to convert to document currency');
            $table->bigInteger('amount_in_document_currency')->default(0)->comment('In minor units (scale 10000)');
            $table->string('billable_to', 30)->default('client')->comment('client, supplier, company');
            $table->foreignId('supplier_company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->date('cost_date')->nullable();
            $table->string('status', 30)->default('pending');
            $table->text('notes')->nullable();
            $table->string('attachment_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('cost_type');
            $table->index('billable_to');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('additional_costs');
    }
};
