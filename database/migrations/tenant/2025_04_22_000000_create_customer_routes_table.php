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
        Schema::create('customer_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('salesman_id')->constrained()->onDelete('cascade');
            $table->enum('frequency', ['weekly', 'biweekly', 'monthly']);
            $table->integer('day_value'); // Day of week (1-7) for weekly, Day of month (1-31) for biweekly/monthly
            $table->boolean('active')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes for better performance
            $table->index(['customer_id', 'active']);
            $table->index(['salesman_id', 'active']);
            $table->index(['frequency', 'day_value']);

            // Ensure one active route per customer
            $table->unique(['customer_id', 'active'], 'unique_active_customer_route');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_routes');
    }
};
