<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTenantsTable extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name')->nullable(); 
            $table->string('email')->nullable();
            $table->json('data')->nullable();
            $table->foreignId('subscription_plan_id')->nullable()->constrained('subscription_plans');
            $table->date('subscription_start_date')->nullable();
            $table->date('subscription_end_date')->nullable();
            $table->enum('subscription_status', ['active', 'expired', 'cancelled', 'trial'])->default('trial');
            $table->boolean('auto_renew')->default(false);
            $table->timestamp('last_billing_date')->nullable();
            $table->timestamp('next_billing_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropForeign(['subscription_plan_id']);
            $table->dropColumn([
                'subscription_plan_id',
                'subscription_start_date',
                'subscription_end_date',
                'subscription_status',
                'auto_renew',
                'last_billing_date',
                'next_billing_date'
            ]);
        });
    }
}
