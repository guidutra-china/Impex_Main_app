<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_term_stages', function (Blueprint $table) {
            $table->smallInteger('days')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('payment_term_stages', function (Blueprint $table) {
            $table->unsignedSmallInteger('days')->default(0)->change();
        });
    }
};
