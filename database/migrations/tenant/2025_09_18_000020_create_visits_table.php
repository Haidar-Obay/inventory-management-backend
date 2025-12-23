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

            // Note: Services and specialists are now stored in the visit_service pivot table
            // This allows multiple services and specialists per visit

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

        // Create visit_service pivot table for multiple services/specialists per visit
        Schema::create('visit_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('specialist_id')->nullable()->constrained('specialists')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['visit_id', 'service_id']);
            $table->index(['specialist_id', 'service_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_service');
        Schema::dropIfExists('visits');
    }
};
