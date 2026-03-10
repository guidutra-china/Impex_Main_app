<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop the table if it exists from a previous failed migration
        Schema::dropIfExists('proforma_invoice_supplier_quotation');

        Schema::create('proforma_invoice_supplier_quotation', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('proforma_invoice_id');
            $table->unsignedBigInteger('supplier_quotation_id');

            $table->foreign('proforma_invoice_id', 'pi_sq_pi_id_foreign')
                ->references('id')
                ->on('proforma_invoices')
                ->cascadeOnDelete();

            $table->foreign('supplier_quotation_id', 'pi_sq_sq_id_foreign')
                ->references('id')
                ->on('supplier_quotations')
                ->cascadeOnDelete();

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
