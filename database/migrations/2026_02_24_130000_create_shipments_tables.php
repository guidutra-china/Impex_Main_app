<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('company_id')->constrained('companies');
            $table->string('status')->default('draft');
            $table->string('transport_mode')->nullable();
            $table->string('container_type')->nullable();
            $table->string('currency_code', 3)->nullable();

            // Carrier & Forwarder
            $table->string('carrier')->nullable();
            $table->string('freight_forwarder')->nullable();
            $table->string('booking_number')->nullable();

            // Transport details
            $table->string('bl_number')->nullable();
            $table->string('container_number')->nullable();
            $table->string('vessel_name')->nullable();
            $table->string('voyage_number')->nullable();

            // Ports
            $table->string('origin_port')->nullable();
            $table->string('destination_port')->nullable();

            // Dates
            $table->date('etd')->nullable();
            $table->date('eta')->nullable();
            $table->date('actual_departure')->nullable();
            $table->date('actual_arrival')->nullable();

            // Weight & Volume
            $table->decimal('total_gross_weight', 12, 3)->nullable();
            $table->decimal('total_net_weight', 12, 3)->nullable();
            $table->decimal('total_volume', 12, 4)->nullable();
            $table->integer('total_packages')->nullable();

            // Notes
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proforma_invoice_item_id')->constrained('proforma_invoice_items');
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->integer('quantity');
            $table->string('unit')->nullable();
            $table->decimal('unit_weight', 10, 3)->nullable();
            $table->decimal('total_weight', 12, 3)->nullable();
            $table->decimal('total_volume', 12, 4)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('packing_list_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('carton_number');
            $table->foreignId('shipment_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('gross_weight', 10, 3)->nullable();
            $table->decimal('net_weight', 10, 3)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('volume', 10, 4)->nullable();
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packing_list_items');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
    }
};
