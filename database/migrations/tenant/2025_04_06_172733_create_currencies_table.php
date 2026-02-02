<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code');
            $table->string('iso_code');
            $table->decimal('rate', 10, 4)->default(1.0000); // Rate relative to primary currency
            $table->enum('rate_source', ['manual', 'api', 'scheduled'])->default('manual');
            $table->timestamp('rate_updated_at')->nullable();
            $table->string('rate_updated_by')->nullable(); // User who updated
            $table->boolean('auto_update_enabled')->default(false); // Allow scheduled updates
            $table->string('symbol')->nullable();
            $table->timestamps();

            $table->index('code');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
