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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            // Invoice identification
            $table->string('invoice_number')->unique()->index();
            $table->enum('invoice_type', ['purchase', 'sale'])->index();
            $table->integer('year')->index(); // For sequence tracking (2025, 2026, etc.)
            $table->integer('sequence_number')->index(); // 0, 1, 2, etc.

            // Dates
            $table->date('date')->index();
            $table->date('due_date')->nullable(); // Calculated: date + payment_term.nb_days

            // Relationships - nullable based on invoice type
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete(); // Only for sales
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete(); // Only for purchase
            $table->foreignId('currency_id')->constrained('currencies')->restrictOnDelete();
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen')->nullOnDelete(); // Only for sales
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms')->nullOnDelete(); // For due_date calculation

            // Denormalized data (snapshots at invoice time)
            $table->string('customer_name')->nullable(); // Customer name at invoice time
            $table->string('salesman_name')->nullable(); // Salesman name at invoice time
            $table->string('customer_phone_number')->nullable(); // Customer phone at invoice time

            // Reference
            $table->string('ref_2')->nullable(); // Reference number
            $table->string('sales_order')->nullable(); // Sales order number
            $table->string('supplier_invoice_number')->nullable(); // Supplier invoice number (for purchase invoices)
            $table->date('supplier_invoice_date')->nullable(); // Supplier invoice date (date when supplier created the invoice, for purchase invoices)

            // Exchange rate
            $table->decimal('exchange_rate', 12, 4)->nullable()->default(1.0000); // Exchange rate for currency conversion

            // Document-level discount
            $table->enum('discount_2_type', ['percent', 'amount'])->nullable();
            $table->decimal('discount_2_value', 12, 2)->nullable()->default(0);

            // Financial totals (calculated, stored for performance and audit)
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('taxes', 12, 2)->default(0);
            $table->decimal('net_total', 12, 2)->default(0);
            $table->decimal('adjustment', 12, 2)->default(0); // Can be positive or negative
            $table->decimal('net_to_pay', 12, 2)->default(0); // net_total + adjustment

            // Physical totals (calculated, stored for performance and audit - mainly for purchase invoices)
            $table->decimal('total_boxes', 10, 4)->default(0)->nullable(); // Total boxes across all items
            $table->decimal('total_pieces', 10, 4)->default(0)->nullable(); // Total pieces across all items
            $table->decimal('total_weight', 10, 4)->default(0)->nullable(); // Total weight (sum of quantity * weight per unit)
            $table->decimal('total_volume', 10, 4)->default(0)->nullable(); // Total volume (sum of quantity * volume per unit)

            // Notes
            $table->text('notes')->nullable();

            // Multiple phones/addresses - stored as JSON arrays (snapshots at invoice time)
            $table->json('billing_to_phones')->nullable(); // Array of phone strings
            $table->json('billing_to_addresses')->nullable(); // Array of address line strings
            $table->json('shipping_to_phones')->nullable();
            $table->json('shipping_to_addresses')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index(['invoice_type', 'date']);
            $table->index(['customer_id', 'date']);
            $table->index(['supplier_id', 'date']);
            $table->index(['warehouse_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
