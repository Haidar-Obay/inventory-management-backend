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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->timestamps();
            $table->softDeletes();
        });

        // Add foreign key constraint if customers table exists
        if (Schema::hasTable('customers')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
