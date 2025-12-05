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
        // Create appointment_service pivot table with asset_id and specialist_id per service
        Schema::create('appointment_service', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->onDelete('cascade');
            $table->foreignId('service_id')->constrained('services')->onDelete('cascade');
            $table->foreignId('specialist_id')->nullable()->constrained('specialists')->onDelete('cascade');
            $table->foreignId('asset_id')->nullable()->constrained('assets')->onDelete('cascade');
            $table->timestamps();

            $table->unique(['appointment_id', 'service_id']);
            $table->index(['specialist_id', 'service_id']);
            $table->index(['asset_id', 'service_id']);
        });

        // Remove asset_id from appointments table (now stored per-service in pivot)
        Schema::table('appointments', function (Blueprint $table) {
            // Drop the index first
            $table->dropIndex(['asset_id', 'status']);
            
            // Drop the foreign key constraint
            $table->dropForeign(['asset_id']);
            
            // Drop the column
            $table->dropColumn('asset_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Re-add asset_id to appointments table
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('asset_id')->nullable()->after('id')
                ->constrained('assets')->onDelete('cascade');
            $table->index(['asset_id', 'status']);
        });

        // Drop appointment_service pivot table
        Schema::dropIfExists('appointment_service');
    }
};

