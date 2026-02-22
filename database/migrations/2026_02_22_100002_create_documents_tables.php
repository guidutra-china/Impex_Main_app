<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable');
            $table->string('type', 30)->comment('e.g. proforma_invoice, commercial_invoice, packing_list, bill_of_lading, certificate_of_origin');
            $table->string('name');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedInteger('version')->default(1);
            $table->string('source', 20)->default('generated')->comment('generated or uploaded');
            $table->string('checksum', 64)->nullable()->comment('SHA256 hash');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->nullable()->comment('File size in bytes');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['documentable_type', 'documentable_id', 'type']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('version');
            $table->string('checksum', 64)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['document_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
    }
};
