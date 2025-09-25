<?php

namespace Database\Factories;

use App\Models\Referrer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReferrerFactory extends Factory
{
    protected $model = Referrer::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'phone1' => $this->faker->phoneNumber(),
            'email' => $this->faker->email(),
            'active' => true,
        ];
    }
}
