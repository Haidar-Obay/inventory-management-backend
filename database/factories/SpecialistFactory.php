<?php

namespace Database\Factories;

use App\Models\Specialist;
use Illuminate\Database\Eloquent\Factories\Factory;

class SpecialistFactory extends Factory
{
    protected $model = Specialist::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}