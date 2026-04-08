<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->string('subject')->nullable();
            $table->string('type')->default('direct'); // direct | group | entity_thread
            $table->nullableMorphs('subject_entity'); // optional anchor entity (subject_entity_id + subject_entity_type)
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'last_message_at']);
        });

        Schema::create('conversation_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('member'); // owner | member
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'last_read_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->text('body'); // raw text input (sanitized)
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('message_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            $table->morphs('referenceable'); // referenceable_id + referenceable_type
            $table->string('reference_code'); // e.g. "PI-2026-00123"
            $table->string('document_type', 8); // e.g. "PI" — denormalized prefix for fast filter
            $table->timestamps();

            $table->index('reference_code');
            $table->index(['message_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_references');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('conversations');
    }
};
