<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('state_transitions', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->string('from_status', 30);
            $table->string('to_status', 30);
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['model_type', 'model_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('state_transitions');
    }
};
