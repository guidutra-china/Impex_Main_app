<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_expenses', function (Blueprint $table) {
            $table->id();
            $table->string('category', 50);
            $table->string('description', 255);
            $table->bigInteger('amount')->default(0)->comment('In minor units (scale 10000)');
            $table->string('currency_code', 10);
            $table->date('expense_date');
            $table->boolean('is_recurring')->default(false);
            $table->unsignedTinyInteger('recurring_day')->nullable()->comment('Day of month for recurring expenses');
            $table->foreignId('payment_method_id')
                ->nullable()
                ->constrained('payment_methods')
                ->nullOnDelete();
            $table->foreignId('bank_account_id')
                ->nullable()
                ->constrained('bank_accounts')
                ->nullOnDelete();
            $table->string('reference', 255)->nullable()->comment('Receipt or invoice number');
            $table->string('attachment_path', 500)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('expense_date');
            $table->index('is_recurring');
            $table->index(['expense_date', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_expenses');
    }
};
