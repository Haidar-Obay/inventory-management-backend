<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            // Visitor (customer) who arrived at the center
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->onDelete('cascade');

            // Optional linked appointment (visitor may be a walk-in without appointment)
            $table->foreignId('appointment_id')
                ->nullable()
                ->constrained('appointments')
                ->nullOnDelete();

            // Visit status lifecycle: arrived -> in_progress -> completed -> cancelled
            $table->enum('status', ['arrived', 'in_progress', 'completed', 'cancelled'])
                ->default('arrived');

            // Timestamps for each stage (for reporting / audit)
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('in_progress_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // Optional free-text notes for this visit
            $table->text('notes')->nullable();

            // Optional cancellation reason (can be propagated to appointment)
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();

            // Indexes for performance
            $table->index(['status', 'arrived_at']);
            $table->index(['customer_id', 'status']);
            $table->index(['appointment_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
