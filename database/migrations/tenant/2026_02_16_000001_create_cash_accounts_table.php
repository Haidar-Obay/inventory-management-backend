<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // physical_cash | bank | wallet | card
            $table->foreignId('currency_id')->nullable()->constrained('currencies')->nullOnDelete();
            $table->decimal('opening_amount', 18, 4)->default(0);
            $table->date('opening_date')->nullable();
            // Bank (when type = bank)
            $table->string('bank_name')->nullable();
            $table->string('branch')->nullable();
            $table->string('company_name')->nullable();
            $table->string('iban')->nullable();
            $table->string('swift')->nullable();
            // Wallet / Card (when type = wallet or card)
            $table->string('holder_name')->nullable(); // name on card/wallet
            $table->string('number')->nullable();      // card number / wallet number
            $table->string('phone')->nullable();
            $table->date('expiry_date')->nullable();
            $table->string('cvv', 10)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_accounts');
    }
};
