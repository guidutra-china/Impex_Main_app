<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proforma_invoice_supplier_quotation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proforma_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_quotation_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(
                ['proforma_invoice_id', 'supplier_quotation_id'],
                'pi_sq_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proforma_invoice_supplier_quotation');
    }
};
