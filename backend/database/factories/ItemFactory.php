<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => 'SKU-'.strtoupper(fake()->unique()->bothify('???-####')),
            'name' => fake()->words(3, true),
            'category' => fake()->randomElement([
                'Housekeeping Amenities',
                'Dry Groceries',
                'Beverages',
                'Cleaning Supplies',
                'Maintenance Parts',
            ]),
            'uom' => fake()->randomElement(['PCS', 'BOX', 'KG', 'L', 'BTL', 'PACK']),
            'min_par_level' => fake()->randomFloat(3, 5, 100),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }
}
