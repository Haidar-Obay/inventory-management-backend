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
            $table->foreignId('country_id')->nullable()->constrained('countries');
            $table->foreignId('city_id')->nullable()->constrained('cities');
            $table->foreignId('district_id')->nullable()->constrained('districts');
            $table->foreignId('zone_id')->nullable()->constrained('zones');
            $table->string('building')->nullable();
            $table->string('block')->nullable();
            $table->string('floor')->nullable();
            $table->string('side')->nullable();
            $table->string('appartment')->nullable();
            $table->string('zip_code')->nullable();
            // $table->string('complex')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
