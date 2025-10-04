<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('associations', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Markup/Markdown: value + type (percent or amount)
            $table->decimal('markup_value', 10, 2)->nullable();
            $table->enum('markup_type', ['percent', 'amount'])->nullable();
            $table->decimal('markdown_value', 10, 2)->nullable();
            $table->enum('markdown_type', ['percent', 'amount'])->nullable();

            $table->boolean('allowed_to_pay_for_guests')->default(false);
            $table->boolean('active')->default(true);

            $table->timestamps();
        });

        Schema::create('association_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('association_id')->constrained('associations')->onDelete('cascade');
            $table->string('contact_name');
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('association_contacts');
        Schema::dropIfExists('associations');
    }
};
