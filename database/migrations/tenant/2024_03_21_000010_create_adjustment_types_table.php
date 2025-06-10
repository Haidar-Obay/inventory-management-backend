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
        Schema::create('adjustment_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('cost_type');
            $table->boolean('need_supplier')->default(false);
            $table->boolean('need_customer')->default(false);
            $table->boolean('need_employee')->default(false);
            $table->boolean('need_second_warehouse')->default(false);
            $table->enum('transaction_type', ['in', 'out', 'in_out'])->default('in');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('adjustment_types');
    }
};
