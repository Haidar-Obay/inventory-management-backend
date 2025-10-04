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
        Schema::create('service_advanced_pricings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('specialist_id')->constrained('specialists')->onDelete('cascade');
            $table->decimal('price_on_site', 10, 2)->default(0);
            $table->decimal('price_on_call', 10, 2)->default(0);
            $table->timestamps();

            $table->unique(['service_id', 'specialist_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_advanced_pricings');
    }
};
