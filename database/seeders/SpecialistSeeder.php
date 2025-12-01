<?php

namespace Database\Seeders;

use App\Models\Specialist;
use App\Models\Speciality;
use Illuminate\Database\Seeder;

class SpecialistSeeder extends Seeder
{
    public function run(): void
    {
        $radiology = Speciality::where('name', 'Radiology')->first();

        $jane = Specialist::firstOrCreate(
            ['name' => 'Dr. Jane Miller'],
            [
                'phone_1' => '+1-555-0101',
                'phone_2' => '+1-555-0102',
                'address' => '123 Medical Center Dr, Suite 100',
                'email' => 'jane.miller@example.com',
            ]
        );
        $alan = Specialist::firstOrCreate(
            ['name' => 'Dr. Alan Smith'],
            [
                'phone_1' => '+1-555-0201',
                'phone_2' => '+1-555-0202',
                'address' => '456 Health Plaza, Suite 200',
                'email' => 'alan.smith@example.com',
            ]
        );

        if ($radiology) {
            $jane->specialities()->syncWithoutDetaching([$radiology->id]);
            $alan->specialities()->syncWithoutDetaching([$radiology->id]);
        }
    }
}
