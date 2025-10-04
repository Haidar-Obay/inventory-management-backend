<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            // Personal Information
            $table->id();
            $table->enum('title', ['Mr.', 'Mrs.', 'Ms.', 'Dr.', 'Prof.'])->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('display_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('phone1');
            $table->string('phone2')->nullable();
            $table->string('phone3')->nullable();

            // Business Information
            $table->string('file_number')->nullable();
            $table->string('barcode')->nullable();
            $table->json('search_terms')->nullable()->comment('JSON array of search keywords for supplier lookup');

            // Categorize
            $table->foreignId('trade_id')->nullable()->constrained('trades');
            $table->foreignId('supplier_group_id')->nullable()->constrained('supplier_groups');
            $table->foreignId('business_type_id')->nullable()->constrained('business_types');
            $table->enum('indicator', ['A', 'B', 'C', 'D'])->nullable();

            // Opening
            $table->foreignId('currency_id')->nullable()->constrained('currencies');
            $table->decimal('opening_amount', 15, 2)->nullable()->default(0.00);
            $table->date('opening_date')->nullable();

            // Payment Terms
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->decimal('credit_limit', 15, 2)->nullable()->default(0.00);
            $table->enum('payment_day', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30'])->nullable();
            $table->enum('track_payment', ['yes', 'no'])->default('no');
            $table->enum('settlement_method', ['FIFO', 'Manual'])->nullable();
            $table->boolean('accept_cheques')->default(false);
            $table->integer('max_cheques')->nullable()->default(0);

            // More Options
            $table->text('notes')->nullable();

            // Taxes
            $table->boolean('taxable')->nullable()->default(false);
            $table->date('taxed_from_date')->nullable()->comment('Date from which supplier is taxable');
            $table->date('taxed_till_date')->nullable()->comment('Date until which supplier is taxable');
            $table->boolean('subjected_to_tax')->nullable()->default(false)->comment('Whether supplier is subjected to added tax');
            $table->decimal('added_tax', 5, 2)->nullable()->default(0.00)->comment('Added tax percentage for this supplier');

            // Catalog (nullable for future implementation)
            $table->text('catalog')->nullable();

            // Status flags
            $table->boolean('is_foreign')->default(false);
            $table->boolean('active')->default(true);
            $table->boolean('add_message')->default(false);
            $table->text('message')->nullable()->comment('Custom message for this supplier');

            // Primary contact reference
            $table->unsignedBigInteger('contacts_id')->nullable()->comment('Primary contact for this supplier');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
