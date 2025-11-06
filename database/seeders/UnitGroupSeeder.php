<?php

namespace Database\Seeders;

use App\Models\UnitGroup;
use Illuminate\Database\Seeder;

class UnitGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $unitGroups = [
            ['name' => 'Weight'],
            ['name' => 'Volume'],
            ['name' => 'Length'],
            ['name' => 'Count'],
            ['name' => 'Area'],
        ];

        foreach ($unitGroups as $group) {
            UnitGroup::firstOrCreate(
                ['name' => $group['name']],
                $group
            );
        }
    }
}
