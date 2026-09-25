<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory(),
            'type' => 'full',
            'amount' => fake()->randomFloat(2, 100, 1000),
            'method' => 'cash',
            'reference_no' => null,
            'status' => 'paid',
            'paid_at' => now(),
            'recorded_by' => User::factory(),
        ];
    }
}
