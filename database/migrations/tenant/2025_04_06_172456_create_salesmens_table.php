<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salesmen', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('address')->nullable();
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('email')->nullable();
            $table->decimal('fix_commission', 8, 2)->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
        });;
    }

    public function down(): void
    {
        Schema::dropIfExists('salesmens');
    }
};
