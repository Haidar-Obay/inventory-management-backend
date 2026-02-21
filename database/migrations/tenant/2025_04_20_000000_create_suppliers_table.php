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
            $table->string('bar_code')->nullable();
            $table->json('search_terms')->nullable()->comment('JSON array of search keywords for supplier lookup');

            // Categorize
            $table->foreignId('trade_id')->nullable()->constrained('trades');
            $table->foreignId('supplier_group_id')->nullable()->constrained('supplier_groups');
            $table->foreignId('business_type_id')->nullable()->constrained('business_types');
            $table->enum('indicator', ['A', 'B', 'C', 'D'])->nullable();

            // Opening balances are handled in separate supplier_opening_balances table

            // Payment Terms (payment_term_id, payment_method_id, allow_credit, accept_cheques are per-currency in supplier_opening_balances)

            // Credit limits and cheque limits are handled in separate tables for multi-currency support

            // More Options
            $table->text('notes')->nullable();

            // Taxes
            $table->boolean('taxable')->nullable()->default(false);
            $table->date('taxed_from_date')->nullable()->comment('Date from which supplier is taxable');
            $table->date('taxed_till_date')->nullable()->comment('Date until which supplier is taxable');
            $table->boolean('subjected_to_tax')->nullable()->default(false)->comment('Whether supplier is subjected to added tax');
            $table->decimal('added_tax', 5, 2)->nullable()->default(0.00)->comment('Added tax percentage for this supplier');
            $table->boolean('exempted')->default(false)->comment('Whether supplier is tax exempted');
            $table->string('exempted_from')->nullable()->comment('Reason for tax exemption');
            $table->string('exemption_reference')->nullable()->comment('Reference number for tax exemption');
            $table->date('exempted_from_date')->nullable()->comment('Tax exemption start date');
            $table->date('exempted_till_date')->nullable()->comment('Tax exemption end date');

            // Catalog (nullable for future implementation)
            $table->text('catalog')->nullable();

            // Status flags
            $table->enum('invoicing_mode', ['open price', 'predefined', 'last price'])->nullable();
            $table->boolean('is_foreign')->default(false);
            $table->boolean('active')->default(true);
            $table->text('message')->nullable()->comment('Custom message for this supplier');

            // Primary contact reference
            $table->unsignedBigInteger('contacts_id')->nullable()->comment('Primary contact for this supplier');

            $table->timestamps();
        });

        // Add foreign key constraint to addresses table if it exists
        if (Schema::hasTable('addresses') && Schema::hasColumn('addresses', 'supplier_id')) {
            try {
                Schema::table('addresses', function (Blueprint $table) {
                    $table->foreign('supplier_id')
                        ->references('id')
                        ->on('suppliers')
                        ->onDelete('cascade');
                });
            } catch (\Exception $e) {
                // Foreign key might already exist, ignore
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
