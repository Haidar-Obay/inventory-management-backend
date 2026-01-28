<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Creates sub_categories table (name + category_id) and, if categories still has subcategory_of, migrates data and drops the column.
     */
    public function up(): void
    {
        Schema::create('sub_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->constrained('categories')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['category_id', 'name']);
        });

        // If categories has subcategory_of (legacy), migrate into sub_categories and drop the column
        if (Schema::hasColumn('categories', 'subcategory_of')) {
            $rows = DB::table('categories')->whereNotNull('subcategory_of')->get(['id', 'name', 'subcategory_of']);
            foreach ($rows as $row) {
                DB::table('sub_categories')->insertOrIgnore([
                    'name' => $row->name,
                    'category_id' => $row->subcategory_of,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Schema::table('categories', function (Blueprint $table) {
                $table->dropForeign(['subcategory_of']);
                $table->dropColumn('subcategory_of');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore categories.subcategory_of from sub_categories before dropping the table
        if (Schema::hasTable('sub_categories') && ! Schema::hasColumn('categories', 'subcategory_of')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->unsignedBigInteger('subcategory_of')->nullable()->after('product_line_id');
            });

            foreach (DB::table('sub_categories')->get() as $link) {
                DB::table('categories')
                    ->where('name', $link->name)
                    ->update(['subcategory_of' => $link->category_id]);
            }

            Schema::table('categories', function (Blueprint $table) {
                $table->foreign('subcategory_of')->references('id')->on('categories')->onDelete('restrict');
            });
        }

        Schema::dropIfExists('sub_categories');
    }
};
