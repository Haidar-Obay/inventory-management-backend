<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->string('address_line1');
            $table->string('address_line2')->nullable();

            $table->unsignedBigInteger('country_id');
            $table->unsignedBigInteger('city_id');
            $table->unsignedBigInteger('province_id');
            $table->unsignedBigInteger('district_id')->nullable();

            $table->string('postal_code')->nullable();
            $table->string('complex')->nullable();
            $table->string('building')->nullable();
            $table->string('floor')->nullable();
            $table->string('side')->nullable();
            $table->string('unit_number')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
