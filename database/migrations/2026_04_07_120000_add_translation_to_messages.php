<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Detected source language (e.g. "en", "pt_BR", "zh_CN").
            // Nullable: detection runs best-effort on send.
            $table->string('detected_locale', 10)->nullable()->after('body');
            $table->index('detected_locale');
        });

        Schema::create('message_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->string('locale', 10);          // target locale, e.g. "pt_BR"
            $table->text('body');                  // translated text
            $table->string('provider', 32)->default('claude');
            $table->timestamp('created_at')->nullable();
            // No updated_at — translations are immutable.

            $table->unique(['message_id', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_translations');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['detected_locale']);
            $table->dropColumn('detected_locale');
        });
    }
};
