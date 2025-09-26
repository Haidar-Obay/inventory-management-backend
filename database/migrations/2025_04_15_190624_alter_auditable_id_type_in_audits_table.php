<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // SQLite doesn't support altering column types this way; skip in CI/sqlite
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('audits', function (Blueprint $table) {
            DB::statement('ALTER TABLE audits ALTER COLUMN auditable_id TYPE TEXT;');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Skip on SQLite for the same reason as in up()
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        Schema::table('audits', function (Blueprint $table) {
            DB::statement('ALTER TABLE audits ALTER COLUMN auditable_id TYPE BIGINT USING auditable_id::BIGINT;');
        });
    }
};
