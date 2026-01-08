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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->restrictOnDelete();

            // Snapshot data at time of invoice (for historical accuracy)
            $table->string('barcode')->nullable(); // From item_unit_of_measurement.barcodes array
            $table->text('description'); // Snapshot of item description
            $table->foreignId('uom_id')->constrained('unit_of_measurements')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();

            // Pricing and quantities
            $table->decimal('quantity', 10, 4);
            $table->decimal('price', 12, 2); // Price of the selected UOM
            $table->decimal('unit_price', 12, 2); // Base unit price: price / conversion_factor
            $table->decimal('discount_percent', 5, 2)->default(0); // 0-100
            $table->decimal('tax_percent', 5, 2)->default(0); // 0-100

            // Calculated totals
            $table->decimal('subtotal', 12, 2); // quantity * price
            $table->decimal('total', 12, 2); // After discount and tax

            $table->timestamps();

            // Indexes
            $table->index('invoice_id');
            $table->index('item_id');
            $table->index('warehouse_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
