<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('iso_code');
            $table->decimal('rate', 10, 4)->default(1.0000); // Rate relative to primary currency
            $table->enum('rate_source', ['manual', 'api', 'scheduled'])->default('manual');
            $table->timestamp('rate_updated_at')->nullable();
            $table->string('rate_updated_by')->nullable(); // User who updated
            $table->boolean('auto_update_enabled')->default(false); // Allow scheduled updates
            $table->string('symbol')->nullable();
            $table->decimal('smallest_unit', 20, 6)->nullable(); // e.g. 0.01 for cents
            $table->decimal('round_limit', 20, 6)->nullable();
            $table->decimal('acceptable_amount_overdue', 20, 4)->nullable();
            $table->decimal('allowed_difference_in_receipt', 20, 4)->nullable();
            $table->decimal('allowed_difference_in_payment', 20, 4)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
