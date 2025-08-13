<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->foreignId('address_id')->constrained('addresses')->onDelete('cascade');
            $table->enum('address_type', ['billing', 'shipping'])->default('billing');
            $table->boolean('is_primary')->default(false);
            $table->string('address_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ensure unique combination of supplier, address, and type
            $table->unique(['supplier_id', 'address_id', 'address_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_addresses');
    }
};
