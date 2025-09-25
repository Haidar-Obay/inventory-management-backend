<?php

namespace Database\Seeders;

use App\Models\Referrer;
use App\Models\ReferrerServiceCommission;
use App\Models\Service;
use Illuminate\Database\Seeder;

class ReferrerSeeder extends Seeder
{
    public function run(): void
    {
        $ref = Referrer::firstOrCreate([
            'name' => 'Dr. Referral',
        ], [
            'address' => '221B Baker Street, London',
            'phone1' => '+44-20-7946-0958',
            'email' => 'ref@clinic.co.uk',
            'active' => true,
            'commission_percent' => 7.50,
        ]);

        $service = Service::where('name', 'MRI Brain Scan')->first();
        if ($service) {
            ReferrerServiceCommission::updateOrCreate([
                'referrer_id' => $ref->id,
                'service_id' => $service->id,
            ], [
                'price_override' => 235,
                'discount_override' => null,
                'commission_percent' => 8.00,
            ]);
        }
    }
}


