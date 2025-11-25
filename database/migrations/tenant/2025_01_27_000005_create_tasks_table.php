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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->morphs('schedulable'); // Creates schedulable_id and schedulable_type (auto-set by backend based on user)
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date'); // Date only (no time)
            $table->time('time')->nullable(); // Time only (nullable for all-day tasks)
            $table->boolean('is_all_day')->default(false);
            $table->json('repeat')->nullable(); // Recurring pattern (e.g., {"frequency": "daily", "interval": 1, "end_date": null})
            $table->date('due_at')->nullable(); // Deadline (due date)
            $table->enum('status', ['completed', 'uncompleted'])->default('uncompleted');
            $table->enum('priority', ['low', 'medium', 'high', 'urgent'])->default('medium');
            $table->string('color', 7)->nullable(); // Hex color code (e.g., #FF5733)
            $table->timestamps();

            // Add indexes for better performance
            $table->index(['date', 'time']);
            $table->index(['schedulable_id', 'schedulable_type']);
            $table->index(['status', 'priority']);
            $table->index('due_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
