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
        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('type')->index();
            $table->foreignId('parent_id')->nullable()->constrained('items')->nullOnDelete();
            $table->foreignId('base_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->foreignId('trade_id')->nullable()->constrained('trades')->nullOnDelete();
            $table->foreignId('company_code_id')->nullable()->constrained('company_codes')->nullOnDelete();
            $table->foreignId('product_line_id')->nullable()->constrained('product_lines')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained('brands')->nullOnDelete();
            $table->foreignId('tax_group_id')->nullable()->constrained('tax_groups')->nullOnDelete();

            // Pricing / discount
            $table->decimal('discount_percent', 5, 2)->default(0); // 0-100
            $table->decimal('max_discount', 10, 2)->nullable();

            // Flags
            $table->boolean('active')->default(true);
            $table->boolean('allow_discount')->default(false);
            $table->boolean('allow_credit')->default(false);
            $table->boolean('allow_return')->default(false);
            $table->boolean('available_for_sale')->default(true);
            $table->boolean('raw_material')->default(false);
            $table->boolean('produced_item')->default(false);
            // purchase/sales parameters removed during development
            $table->text('purchase_description')->nullable();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->text('sales_description')->nullable();
            $table->text('pos_description')->nullable();
            $table->foreignId('sales_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
