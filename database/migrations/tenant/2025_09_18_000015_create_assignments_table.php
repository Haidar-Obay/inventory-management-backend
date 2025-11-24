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
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->nullable()->constrained('assets')->onDelete('cascade');
            $table->foreignId('specialist_id')->nullable()->constrained('specialists')->onDelete('cascade');
            $table->timestamp('start_at');
            $table->timestamp('end_at');
            $table->enum('status', ['active', 'in_progress', 'completed'])->default('active');
            $table->text('notes')->nullable();
            $table->string('color', 7)->nullable(); // Hex color code (e.g., #FF5733)
            $table->timestamps();

            // Add index for better performance on date queries
            $table->index(['start_at', 'end_at']);
            $table->index(['asset_id', 'status']);
            $table->index(['specialist_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
