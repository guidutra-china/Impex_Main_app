<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('weight', 5, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('audit_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audit_category_id')->constrained('audit_categories')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('type')->default('scored');
            $table->boolean('is_critical')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('supplier_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('audit_type');
            $table->string('status')->default('scheduled');
            $table->string('result')->nullable();
            $table->date('scheduled_date');
            $table->date('conducted_date')->nullable();
            $table->foreignId('conducted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('location')->nullable();
            $table->decimal('total_score', 4, 2)->nullable();
            $table->text('summary')->nullable();
            $table->text('corrective_actions')->nullable();
            $table->date('next_audit_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'status']);
            $table->index(['status', 'scheduled_date']);
        });

        Schema::create('audit_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_audit_id')->constrained('supplier_audits')->cascadeOnDelete();
            $table->foreignId('audit_criterion_id')->constrained('audit_criteria')->cascadeOnDelete();
            $table->unsignedTinyInteger('score')->nullable();
            $table->boolean('passed')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['supplier_audit_id', 'audit_criterion_id'], 'audit_response_unique');
        });

        Schema::create('audit_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_audit_id')->constrained('supplier_audits')->cascadeOnDelete();
            $table->foreignId('audit_category_id')->nullable()->constrained('audit_categories')->nullOnDelete();
            $table->string('type')->default('photo');
            $table->string('title');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->string('original_name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime_type')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('supplier_audit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_documents');
        Schema::dropIfExists('audit_responses');
        Schema::dropIfExists('supplier_audits');
        Schema::dropIfExists('audit_criteria');
        Schema::dropIfExists('audit_categories');
    }
};
