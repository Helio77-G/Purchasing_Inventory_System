<?php

namespace Database\Factories;

use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Store>
 */
class StoreFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'STR-'.strtoupper(fake()->unique()->lexify('????')),
            'name' => fake()->unique()->word().' Warehouse Store',
            'location' => 'Basement Level '.fake()->numberBetween(1, 3),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
