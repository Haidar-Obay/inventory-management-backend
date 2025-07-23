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
        Schema::create('table_templates', function (Blueprint $table) {
            $table->string('id', 255)->primary();
            $table->string('name', 255);
            $table->string('table_name', 255);
            $table->json('visible_columns');
            $table->json('column_widths');
            $table->json('column_order');
            $table->string('headerColor', 255)->nullable();
            $table->boolean('showHeaderSeparator')->default(false);
            $table->boolean('showHeaderColSeparator')->default(false);
            $table->boolean('showBodyColSeparator')->default(false);
            $table->timestamps();
            
            $table->unique(['table_name', 'name'], 'unique_template_name');
            $table->index(['table_name'], 'idx_table_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('table_templates');
    }
};
