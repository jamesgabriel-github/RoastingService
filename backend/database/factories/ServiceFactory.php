<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'roasting_rate_per_kg' => fake()->randomFloat(2, 100, 250),
            'shop_price' => null,
            'est_minutes' => fake()->numberBetween(60, 240),
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => false,
            'stock_qty' => 0,
            'is_active' => true,
        ];
    }

    public function shopSupplied(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_customer_supplied' => false,
            'allow_shop_supplied' => true,
            'roasting_rate_per_kg' => null,
            'shop_price' => fake()->randomFloat(2, 150, 2000),
        ]);
    }

    public function bothTypes(): static
    {
        return $this->state(fn (array $attributes) => [
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => true,
            'roasting_rate_per_kg' => fake()->randomFloat(2, 100, 250),
            'shop_price' => fake()->randomFloat(2, 150, 2000),
        ]);
    }
}
