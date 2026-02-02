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
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->decimal('rate', 10, 4); // Rate relative to primary currency
            $table->enum('rate_source', ['manual', 'api', 'scheduled'])->default('manual');
            $table->timestamp('effective_from')->default(now());
            $table->timestamp('effective_to')->nullable(); // NULL = current rate
            $table->string('updated_by')->nullable(); // User who updated
            $table->text('notes')->nullable(); // Optional notes about the rate change
            $table->timestamps();

            // Indexes for efficient queries
            $table->index(['currency_id', 'effective_from']);
            $table->index(['currency_id', 'effective_to']);
            $table->index('effective_from');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
