<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('address_id')->constrained()->cascadeOnDelete();
            $table->enum('address_type', ['billing', 'shipping', 'both'])->default('shipping');
            $table->boolean('is_primary')->default(false);
            $table->string('address_name')->nullable(); // e.g., "Home", "Office", "Warehouse"
            $table->text('notes')->nullable();
            $table->timestamps();

            // Ensure a customer can only have one primary address per type
            // Use partial unique index to only enforce uniqueness when is_primary = true
            // This allows multiple is_primary = false addresses but only one is_primary = true per customer per type

            // Remove the overall primary constraint to allow multiple primary addresses (one per type)
            // $table->unique(['customer_id', 'is_primary'], 'unique_primary_address_per_customer');
        });

        // Create partial unique index to only enforce uniqueness when is_primary = true
        // This allows multiple is_primary = false addresses but only one is_primary = true per customer per type
        DB::statement('
            CREATE UNIQUE INDEX unique_primary_address_per_type 
            ON customer_addresses (customer_id, address_type) 
            WHERE is_primary = true
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }
};
