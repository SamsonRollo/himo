<?php

namespace Database\Factories;

use App\Models\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceRequestFactory extends Factory
{
    public function definition(): array
    {
        $neededStart = fake()->dateTimeBetween('+1 day', '+2 weeks');

        return [
            'service_category_id' => ServiceCategory::factory(),
            'location' => 'Room '.fake()->numberBetween(1, 100),
            'description' => fake()->sentence(),
            'needed_start_at' => $neededStart,
            'needed_end_at' => (clone $neededStart)->modify('+2 hours'),
        ];
    }
}
