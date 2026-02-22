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
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            $table->boolean('allow_credit')->default(false);
            $table->enum('payment_day', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30'])->nullable();
            $table->enum('track_payment', ['yes', 'no'])->default('no');
            $table->enum('settlement_method', ['FIFO', 'Manual'])->nullable();
            $table->boolean('accept_cheques')->default(false)->comment('Whether to accept cheques for this currency');
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
