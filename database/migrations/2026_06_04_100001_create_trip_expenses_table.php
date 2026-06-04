<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trip_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->string('category', 40);
            $table->string('description', 255)->nullable();
            $table->bigInteger('amount')->default(0)->comment('In minor units (scale 10000)');
            $table->string('currency_code', 10);
            // Datetime (not just date) so the time-of-day is captured — e.g. a
            // meal at 12h vs 20h tells lunch from dinner.
            $table->dateTime('expense_date');
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('trip_id');
            $table->index(['trip_id', 'category']);
            $table->index('expense_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trip_expenses');
    }
};
