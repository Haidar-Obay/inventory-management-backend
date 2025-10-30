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
            $table->decimal('price', 10, 2);
            $table->string('unit')->nullable();
            $table->string('trade')->nullable();
            $table->string('company_code')->nullable()->index();
            $table->string('product_line')->nullable();
            $table->string('category')->nullable()->index();
            $table->string('brand')->nullable()->index();
            $table->decimal('discount_percent', 5, 2)->default(0); // 0-100
            $table->decimal('max_discount', 10, 2)->nullable();
            $table->json('purchase_parameters')->nullable();
            $table->text('purchase_description')->nullable();
            $table->foreignId('purchase_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->json('sales_parameters')->nullable();
            $table->text('sales_description')->nullable();
            $table->text('pos_description')->nullable();
            $table->foreignId('sales_uom_id')->nullable()->constrained('unit_of_measurements')->nullOnDelete();
            $table->text('description')->nullable();
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
