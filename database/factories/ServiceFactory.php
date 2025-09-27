<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    protected $model = Service::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'service_category_id' => null, // Will be set in tests as needed
            'normal_price' => $this->faker->randomFloat(2, 10, 500),
            'hour_price' => $this->faker->randomFloat(2, 5, 100),
            'price_calculated_by_hour' => $this->faker->boolean(),
            'event_pricing' => $this->faker->boolean(),
            'active' => true,
        ];
    }
}
