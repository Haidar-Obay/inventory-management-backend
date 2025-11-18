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
        Schema::create('specialists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('capacity_per_hour')->nullable()->default(1); // How many appointments per hour
            $table->integer('capacity_per_day')->nullable(); // Max appointments per day (null = unlimited)
            $table->timestamps();
            $table->unique(['name']);
        });

        // Pivot: specialist <-> specialities (many-to-many)
        Schema::create('specialist_speciality', function (Blueprint $table) {
            $table->id();
            $table->foreignId('specialist_id')->constrained('specialists')->onDelete('cascade');
            $table->foreignId('speciality_id')->constrained('specialities')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['specialist_id', 'speciality_id']);
        });

        // Pivot: specialist <-> assets (many-to-many)
        Schema::create('asset_specialist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asset_id')->constrained('assets')->onDelete('cascade');
            $table->foreignId('specialist_id')->constrained('specialists')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['asset_id', 'specialist_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_specialist');
        Schema::dropIfExists('specialist_speciality');
        Schema::dropIfExists('specialists');
    }
};
