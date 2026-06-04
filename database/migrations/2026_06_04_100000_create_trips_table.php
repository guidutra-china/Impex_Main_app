<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();

            // Idempotency key sent by the offline mobile app so a re-synced
            // submission does not create a duplicate trip.
            $table->uuid('client_uuid')->nullable()->unique();

            // Traveler (internal user who made the trip).
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // The trip belongs to a client/supplier OR is an internal expense.
            // Exactly one of company_id / is_internal is enforced at the
            // application layer (TripData / form / request validation).
            $table->foreignId('company_id')
                ->nullable()
                ->constrained('companies')
                ->nullOnDelete();
            $table->boolean('is_internal')->default(false);

            $table->string('title');
            $table->string('destination_city')->nullable();
            $table->string('destination_country', 2)->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();

            $table->string('status', 30)->default('draft');
            $table->text('notes')->nullable();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
            $table->index('company_id');
            $table->index('is_internal');
            $table->index('start_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
