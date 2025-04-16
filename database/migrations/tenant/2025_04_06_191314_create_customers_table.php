<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('suffix')->nullable();
            $table->string('display_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('phone1')->nullable();
            $table->string('phone2')->nullable();
            $table->string('phone3')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('file_number')->nullable();
            $table->foreignId('billing_address_id')->constrained('addresses')->cascadeOnDelete();
            $table->foreignId('shipping_address_id')->constrained('addresses')->cascadeOnDelete();
            $table->boolean('is_sub_customer')->default(false);
            $table->foreignId('parent_customer_id')->nullable()->constrained('customers');
            $table->foreignId('customer_group_id')->nullable()->constrained();
            $table->foreignId('salesman_id')->nullable()->constrained('salesmen');
            $table->foreignId('refer_by_id')->nullable()->constrained('refer_bies');
            $table->foreignId('primary_payment_method_id')->nullable()->constrained('payment_methods');
            $table->foreignId('payment_term_id')->nullable()->constrained('payment_terms');
            $table->decimal('credit_limit', 15, 2)->nullable();
            $table->boolean('taxable')->nullable();
            $table->string('tax_registration')->nullable();
            $table->foreignId('opening_currency_id')->nullable()->constrained('currencies');
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->text('notes')->nullable();
            $table->json('attachment_ids')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->timestamps();
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
