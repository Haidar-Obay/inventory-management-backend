<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    public function run(): void
    {
        // Root departments
        $imaging = Department::firstOrCreate([
            'code' => 'IMG',
        ], [
            'name' => 'Imaging',
            'active' => true,
        ]);

        $lab = Department::firstOrCreate([
            'code' => 'LAB',
        ], [
            'name' => 'Laboratory',
            'active' => true,
        ]);

        // Sub-departments
        Department::firstOrCreate([
            'code' => 'MRI',
        ], [
            'name' => 'MRI Unit',
            'sub_department_of' => $imaging->id,
            'active' => true,
        ]);

        Department::firstOrCreate([
            'code' => 'XRAY',
        ], [
            'name' => 'X-Ray Room',
            'sub_department_of' => $imaging->id,
            'active' => true,
        ]);

        Department::firstOrCreate([
            'code' => 'BLOOD',
        ], [
            'name' => 'Blood Lab',
            'sub_department_of' => $lab->id,
            'active' => true,
        ]);
    }
}
