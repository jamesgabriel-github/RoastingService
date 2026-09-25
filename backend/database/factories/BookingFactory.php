<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'RS-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'customer_id' => User::factory(),
            'source_type' => 'customer_supplied',
            'fulfillment' => 'pickup',
            'shipping_fee' => 0,
            'status' => 'pending_review',
            'preferred_dropoff_at' => now()->addDay(),
            'estimated_total' => 0,
        ];
    }
}
