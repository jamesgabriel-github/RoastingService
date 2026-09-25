<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingItem>
 */
class BookingItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rate = fake()->randomFloat(2, 100, 250);
        $weight = fake()->randomFloat(2, 1, 10);

        return [
            'booking_id' => Booking::factory(),
            'service_id' => Service::factory(),
            'qty' => 1,
            'est_weight_kg' => $weight,
            'rate' => $rate,
            'subtotal' => round($rate * $weight, 2),
        ];
    }
}
