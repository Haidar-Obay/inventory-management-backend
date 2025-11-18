<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('billing_cycle')->default('monthly'); // monthly, yearly
            $table->boolean('is_active')->default(true);
            $table->json('features')->nullable(); // Store features as JSON
            $table->integer('max_currencies')->default(1);
            $table->integer('max_users')->nullable();
            $table->integer('max_customers')->nullable();
            $table->enum('scheduler_mode', ['basic', 'advanced'])->default('basic');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
