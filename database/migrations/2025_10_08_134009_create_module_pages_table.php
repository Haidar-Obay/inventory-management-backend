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
        Schema::create('module_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('module_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('code'); // unique per module
            $table->string('path'); // frontend route path
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['module_id', 'code']);
            $table->unique(['module_id', 'path']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('module_pages');
    }
};
