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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained('sections')->onDelete('cascade');
            $table->string('name');
            $table->enum('type', ['machine', 'bed', 'equipment', 'furniture', 'other'])->default('other');
            $table->enum('status', ['active', 'maintenance', 'inactive', 'retired'])->default('active');
            $table->timestamps();

            // Add unique constraint for name within the same section
            $table->unique(['section_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
