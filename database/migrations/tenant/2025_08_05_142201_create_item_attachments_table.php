<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('item_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('items')->onDelete('cascade');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('file_type')->nullable();
            $table->bigInteger('file_size')->nullable();
            $table->string('description')->nullable();
            $table->enum('category', ['photo', 'document', 'other'])->default('other');
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->index(['item_id', 'category']);
            $table->index('file_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_attachments');
    }
};
