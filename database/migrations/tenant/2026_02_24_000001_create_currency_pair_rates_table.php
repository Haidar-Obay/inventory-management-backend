<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_pair_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_currency_id')->constrained('currencies')->onDelete('cascade');
            $table->foreignId('to_currency_id')->constrained('currencies')->onDelete('cascade');
            $table->decimal('rate', 20, 6); // 1 from = rate × to
            $table->timestamps();

            $table->unique(['from_currency_id', 'to_currency_id']);
            $table->index(['to_currency_id', 'from_currency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_pair_rates');
    }
};
