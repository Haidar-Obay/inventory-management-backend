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
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->enum('status', ['active', 'completed', 'cancelled', 'overdue'])->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            
            // Add index for better performance on date queries
            $table->index(['start_at', 'end_at']);
            $table->index(['asset_id', 'status']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
