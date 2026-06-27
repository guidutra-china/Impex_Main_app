<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Supplier quotations imported from a file (e.g. via the AI Assistant) have no
        // originating inquiry, so the link must be optional.
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->foreignId('inquiry_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_quotations', function (Blueprint $table) {
            $table->foreignId('inquiry_id')->nullable(false)->change();
        });
    }
};
