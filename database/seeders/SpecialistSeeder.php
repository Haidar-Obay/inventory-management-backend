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

        $jane = Specialist::firstOrCreate(['name' => 'Dr. Jane Miller']);
        $alan = Specialist::firstOrCreate(['name' => 'Dr. Alan Smith']);

        if ($radiology) {
            $jane->specialities()->syncWithoutDetaching([$radiology->id]);
            $alan->specialities()->syncWithoutDetaching([$radiology->id]);
        }
    }
}


