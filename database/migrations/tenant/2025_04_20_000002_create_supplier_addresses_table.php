<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->foreignId('address_id')->constrained('addresses')->onDelete('cascade');
            $table->enum('address_type', ['billing', 'shipping', 'both'])->default('shipping');
            $table->boolean('is_primary')->default(false);
            $table->string('address_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

        });

        // Ensure a supplier can only have one primary address per type
        DB::statement('
            CREATE UNIQUE INDEX unique_primary_supplier_address_per_type 
            ON supplier_addresses (supplier_id, address_type) 
            WHERE is_primary = true
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_addresses');
    }
};
