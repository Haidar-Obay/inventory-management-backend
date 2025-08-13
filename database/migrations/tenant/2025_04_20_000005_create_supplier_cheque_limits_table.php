<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_cheque_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->integer('max_cheques')->default(0)->comment('Maximum number of cheques allowed');
            $table->integer('used_cheques')->default(0)->comment('Number of cheques currently used');
            $table->integer('available_cheques')->default(0)->comment('Available cheques (auto-calculated)');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ensure unique combination of supplier and currency
            $table->unique(['supplier_id', 'currency_id']);

            // Indexes for better performance
            $table->index(['supplier_id', 'is_active']);
            $table->index(['currency_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_cheque_limits');
    }
};
