<?php

declare(strict_types=1);

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
        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->id();

            // Company Information
            $table->string('company_name')->nullable();
            $table->string('location')->nullable();

            // Language & Localization
            $table->enum('main_language', ['en', 'ar'])->default('en');
            $table->enum('preferred_mode', ['light', 'dark'])->default('light');
            $table->enum('time_format', ['12', '24'])->default('24');

            // Currency Settings (using regular integer columns to avoid foreign key dependency)
            $table->unsignedBigInteger('primary_currency_id')->nullable();
            $table->unsignedBigInteger('secondary_currency_id')->nullable();

            // Working Hours
            $table->time('working_time_from')->nullable();
            $table->time('working_time_to')->nullable();
            $table->json('days_off')->nullable(); // Array of day names: ['saturday', 'sunday']

            // Setup Status
            $table->boolean('setup_completed')->default(false);
            $table->timestamp('completed_at')->nullable();

            // Additional flexible settings (JSON)
            $table->json('additional_settings')->nullable();

            $table->timestamps();

            // Indexes
            $table->index('setup_completed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
    }
};
