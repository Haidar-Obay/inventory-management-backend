<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('file_name'); // Original file name
            $table->string('file_path'); // Storage path or URL
            $table->string('file_type')->nullable(); // MIME type
            $table->bigInteger('file_size')->nullable(); // File size in bytes
            $table->string('description')->nullable(); // Optional description
            $table->enum('category', ['document', 'image', 'contract', 'invoice', 'other'])->default('other');
            $table->boolean('is_public')->default(false); // Whether file is publicly accessible
            $table->timestamps();

            // Indexes for better performance
            $table->index(['customer_id', 'category']);
            $table->index('file_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_attachments');
    }
};
