<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained('suppliers')->onDelete('cascade');
            $table->enum('title', ['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.'])->nullable();
            $table->string('name');
            $table->string('work_phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('position')->nullable();
            $table->string('extension')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_contacts');
    }
};
