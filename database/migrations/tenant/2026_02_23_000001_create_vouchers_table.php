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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();

            // Voucher identification
            $table->string('voucher_number')->unique()->index();
            $table->enum('type', ['receipt', 'payment'])->index();
            $table->integer('year')->index();
            $table->integer('sequence_number')->index();

            // Dates
            $table->date('date')->index();
            $table->date('effective_date')->nullable()->index();

            // Reference
            $table->string('ref_2')->nullable();

            // Currency and exchange
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->decimal('exchange_rate', 12, 4)->nullable()->default(1.0000);

            // Party (receipt: customer, payment: supplier)
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('customer_name')->nullable();
            $table->string('supplier_name')->nullable();

            // Opening balance snapshot (at voucher time)
            $table->foreignId('opening_balance_currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('opening_balance_amount', 15, 2)->nullable()->default(0);

            // Amount and staff
            $table->decimal('amount', 15, 2)->default(0);
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete();
            $table->foreignId('collector_id')->nullable()->constrained('salesmen')->nullOnDelete();

            // Totals (calculated, stored for performance and audit)
            $table->decimal('total_voucher', 15, 2)->default(0);
            $table->decimal('total_paid', 15, 2)->default(0);
            $table->decimal('total_difference', 15, 2)->default(0);

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'date']);
            $table->index(['customer_id', 'date']);
            $table->index(['supplier_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
