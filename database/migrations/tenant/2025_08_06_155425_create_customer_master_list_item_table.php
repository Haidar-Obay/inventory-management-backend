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
        Schema::create('customer_master_list_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_master_list_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2);
            $table->decimal('discount', 5, 2)->default(0.00);
            $table->timestamps();

            // Ensure unique combination of customer master list and item
            $table->unique(['customer_master_list_id', 'item_id'], 'unique_master_list_item');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_master_list_item');
    }
};
