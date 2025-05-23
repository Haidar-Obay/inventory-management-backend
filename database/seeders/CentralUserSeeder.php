<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Carbon\Carbon;

class CentralUserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'obay',
            'email' => 'obay@gmail.com',
            'password' => Hash::make('12345678'),
            'role' => 'admin',
            'email_verified_at' => Carbon::create(2021, 1, 1),
        ]);
    }
}
