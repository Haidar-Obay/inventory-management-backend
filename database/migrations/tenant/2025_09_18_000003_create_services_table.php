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
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();

            // Foreign references
            $table->foreignId('service_category_id')->nullable()->constrained('service_categories')->nullOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('items')->nullOnDelete();

            $table->integer('result_after_days')->nullable();

            $table->boolean('needs_specialist')->default(false);
            $table->boolean('needs_asset')->default(false);

            $table->integer('duration_minutes')->nullable();

            $table->decimal('normal_price', 10, 2)->nullable();
            $table->decimal('vip_price', 10, 2)->nullable();
            $table->decimal('price_in_group', 10, 2)->nullable();

            $table->boolean('price_calculated_by_hour')->default(false);
            $table->decimal('hour_price', 10, 2)->nullable();

            $table->decimal('cost_price', 10, 2)->nullable();

            $table->decimal('birthday_price', 10, 2)->nullable();
            $table->decimal('wedding_price', 10, 2)->nullable();

            $table->string('image')->nullable();
            $table->string('service_color')->nullable();
            $table->enum('service_sex', ['male', 'female', 'both'])->default('both');
            $table->boolean('active')->default(true);

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
