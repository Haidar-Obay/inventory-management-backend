<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable(); 
            $table->string('email')->nullable();
            $table->timestamps();
            $table->json('data')->nullable();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
}
