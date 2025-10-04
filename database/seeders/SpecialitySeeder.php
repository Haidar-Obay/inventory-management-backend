<?php

namespace Database\Seeders;

use App\Models\Speciality;
use Illuminate\Database\Seeder;

class SpecialitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (['Radiology', 'Cardiology', 'Neurology'] as $name) {
            Speciality::firstOrCreate(['name' => $name]);
        }
    }
}
