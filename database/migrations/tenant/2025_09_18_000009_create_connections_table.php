<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('type_id')->constrained('connection_types')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['name', 'type_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connections');
    }
};


