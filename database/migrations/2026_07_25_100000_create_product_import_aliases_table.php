<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_import_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 500);
            $table->string('alias_normalized', 255);
            $table->string('source', 20); // backfill | import_confirm
            $table->timestamp('last_confirmed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['company_id', 'alias_normalized']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_import_aliases');
    }
};
