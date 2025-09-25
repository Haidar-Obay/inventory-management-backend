<?php

namespace Database\Factories;

use App\Models\Association;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssociationFactory extends Factory
{
    protected $model = Association::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'phone1' => $this->faker->phoneNumber(),
            'email' => $this->faker->email(),
            'active' => true,
        ];
    }
}
