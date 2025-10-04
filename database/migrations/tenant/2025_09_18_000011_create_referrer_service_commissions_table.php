<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrer_service_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('referrers')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->decimal('price_override', 10, 2)->nullable();
            $table->decimal('discount_override', 10, 2)->nullable();
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['referrer_id', 'service_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referrer_service_commissions');
    }
};
