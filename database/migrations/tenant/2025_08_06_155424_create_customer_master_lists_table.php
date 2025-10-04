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
        Schema::create('customer_master_lists', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('name')->unique();
            $table->date('valid_from');
            $table->date('valid_till');
            $table->timestamps();

            // Add indexes for better performance
            $table->index(['valid_from', 'valid_till']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_master_lists');
    }
};
