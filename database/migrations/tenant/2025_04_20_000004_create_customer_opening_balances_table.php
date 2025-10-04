<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_opening_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->onDelete('cascade');
            $table->foreignId('currency_id')->constrained('currencies')->onDelete('cascade');
            $table->decimal('opening_amount', 15, 2)->default(0)->comment('Opening balance amount in this currency');
            $table->date('opening_date')->comment('Date when opening balance was established');
            $table->text('notes')->nullable()->comment('Notes about the opening balance');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Ensure unique combination of customer and currency
            $table->unique(['customer_id', 'currency_id']);

            // Indexes for better performance
            $table->index(['customer_id', 'is_active']);
            $table->index(['currency_id', 'is_active']);
            $table->index('opening_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_opening_balances');
    }
};
