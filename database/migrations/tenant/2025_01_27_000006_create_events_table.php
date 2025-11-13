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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->morphs('schedulable'); // Creates schedulable_id and schedulable_type
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->string('color', 7)->nullable(); // Hex color code (e.g., #FF5733)
            $table->boolean('is_all_day')->default(false);
            $table->timestamps();

            // Add indexes for better performance
            $table->index(['start_at', 'end_at']);
            $table->index(['schedulable_id', 'schedulable_type']);
            $table->index(['status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

