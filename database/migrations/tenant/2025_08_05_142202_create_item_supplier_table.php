<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_supplier', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $table->foreignId('item_id')->constrained('items')->cascadeOnDelete();
            $table->string('original_code')->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('cost', 12, 2)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['supplier_id', 'item_id']);
            $table->index(['item_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_supplier');
    }
};
