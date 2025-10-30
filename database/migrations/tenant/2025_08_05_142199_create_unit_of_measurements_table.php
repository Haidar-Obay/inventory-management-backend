<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unit_of_measurements', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('unit_group_id')->constrained('unit_groups')->cascadeOnDelete();
            $table->enum('operation', ['multiply', 'divide'])->default('multiply');
            $table->decimal('conversion', 10, 4);
            $table->timestamps();

            $table->index(['unit_group_id', 'operation']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_of_measurements');
    }
};


