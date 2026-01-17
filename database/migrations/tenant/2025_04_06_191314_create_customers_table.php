<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
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

            $table->string('email')->nullable();
            $table->string('card_number')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->enum('gender', ['Male', 'Female', 'Other'])->nullable();
            $table->enum('blood_type', ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'])->nullable();
            $table->enum('marital_status', ['Single', 'Married', 'Divorced', 'Widowed', 'Other'])->nullable();
            // $table->string('website')->nullable();

            // Business Information
            $table->string('file_number')->nullable();
            $table->string('bar_code')->nullable();
            $table->json('search_terms')->nullable()->comment('JSON array of search keywords for customer lookup');

            // Category
            $table->foreignId('trade_id')->nullable()->constrained('trades');
            $table->foreignId('company_code_id')->nullable()->constrained('company_codes');
            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups');
            $table->foreignId('business_type_id')->nullable()->constrained('business_types');
            $table->foreignId('sales_channel_id')->nullable()->constrained('sales_channels');
            $table->foreignId('distribution_channel_id')->nullable()->constrained('distribution_channels');
            $table->foreignId('media_channel_id')->nullable()->constrained('media_channels');
            $table->foreignId('media_type_id')->nullable()->constrained('media_types');
            $table->foreignId('referral_id')->nullable()->constrained('referrers');
            $table->enum('indicator', ['A', 'B', 'C', 'D'])->nullable();
            $table->enum('risk_category', ['Low', 'Medium', 'High'])->nullable();

            // salesmen
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen');
            $table->foreignId('collector_id')->nullable()->constrained('salesmen');
            $table->foreignId('supervisor_id')->nullable()->constrained('salesmen');
            $table->foreignId('manager_id')->nullable()->constrained('salesmen');

            // payment terms
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms');
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods');
            $table->boolean('allow_credit')->default(false);
            $table->boolean('accept_cheques')->default(false);
            $table->enum('payment_day', ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12', '13', '14', '15', '16', '17', '18', '19', '20', '21', '22', '23', '24', '25', '26', '27', '28', '29', '30'])->nullable();
            $table->enum('track_payment', ['yes', 'no'])->default('no');
            $table->enum('settlement_method', ['FIFO', 'Manual'])->nullable();

            // pricing
            $table->enum('price_choice', ['price1', 'price2', 'price3', 'price4', 'price5', 'price6', 'last_invoice_price'])->nullable();
            $table->string('price_list')->nullable();
            // $table->decimal('discount_by_item', 8, 2)->nullable()->comment('Discount percentage applied per item');
            $table->decimal('global_discount', 8, 2)->nullable()->comment('Global discount percentage applied to entire order');
            $table->enum('discount_class', ['Silver', 'Gold', 'Platinum'])->nullable();
            $table->decimal('markup_percentage', 8, 2)->nullable()->comment('Markup percentage applied to cost price');
            $table->decimal('markdown_percentage', 8, 2)->nullable()->comment('Markdown percentage applied to selling price');

            // tax
            $table->boolean('taxable')->nullable()->default(false);
            $table->date('taxed_from_date')->nullable()->comment('Date from which customer is taxable');
            $table->date('taxed_till_date')->nullable()->comment('Date until which customer is taxable');
            $table->boolean('subjected_to_tax')->nullable()->default(false)->comment('Whether customer is subjected to added tax');
            $table->decimal('added_tax', 5, 2)->nullable()->default(0.00)->comment('Added tax percentage for this customer');
            $table->boolean('exempted')->default(false)->comment('Whether customer is tax exempted');
            $table->string('exempted_from')->nullable()->comment('Reason for tax exemption');
            $table->string('exemption_reference')->nullable()->comment('Reference number for tax exemption');
            $table->date('exempted_from_date')->nullable()->comment('Tax exemption start date');
            $table->date('exempted_till_date')->nullable()->comment('Tax exemption end date');

            // more details
            $table->boolean('active')->default(true);
            $table->boolean('black_listed')->default(false);
            $table->text('blacklisted_reason')->nullable()->comment('Reason for blacklisting if black_listed is true');
            $table->enum('status', ['Normal', 'VIP'])->default('Normal')->comment('Customer status: Normal or VIP');
            $table->boolean('one_time_account')->default(true);
            $table->boolean('special_account')->default(false);
            $table->boolean('pos_customer')->default(false);
            $table->boolean('cash_customer')->default(false);
            $table->boolean('free_delivery_charge')->default(false);
            $table->enum('print_invoice_language', ['English', 'Arabic'])->default('English');
            $table->enum('send_invoice', ['email', 'sms', 'whatsapp', 'all'])->default('email');

            // Message functionality
            $table->text('message')->nullable()->comment('Custom message to be printed on invoice and sent with invoice');

            // Primary contact reference
            $table->unsignedBigInteger('contacts_id')->nullable()->comment('Primary contact for this customer');

            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Add foreign key constraint to addresses table if it exists
        if (Schema::hasTable('addresses') && Schema::hasColumn('addresses', 'customer_id')) {
            try {
                Schema::table('addresses', function (Blueprint $table) {
                    $table->foreign('customer_id')
                        ->references('id')
                        ->on('customers')
                        ->onDelete('cascade');
                });
            } catch (\Exception $e) {
                // Foreign key might already exist, ignore
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
