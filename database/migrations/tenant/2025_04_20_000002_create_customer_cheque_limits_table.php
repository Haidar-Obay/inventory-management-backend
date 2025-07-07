<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customer_cheque_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->integer('max_cheques')->default(0)->comment('Maximum number of cheques allowed');
            $table->integer('used_cheques')->default(0)->comment('Number of cheques currently used');
            $table->integer('available_cheques')->default(0)->comment('Available cheques (auto-calculated)');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ensure unique combination of customer and currency
            $table->unique(['customer_id', 'currency_id']);

            // Indexes for better performance
            $table->index(['customer_id', 'is_active']);
            $table->index(['currency_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_cheque_limits');
    }
};
