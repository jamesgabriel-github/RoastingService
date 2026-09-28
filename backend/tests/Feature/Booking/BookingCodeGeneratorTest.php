<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Services\Booking\BookingCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookingCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_code_for_a_date_is_sequence_one(): void
    {
        $generator = new BookingCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-05 11:30:00'));

        $this->assertSame('RS-261005-001', $code);
    }

    public function test_sequence_increments_for_existing_bookings_on_the_same_date(): void
    {
        Booking::factory()->count(2)->create(['preferred_pickup_at' => '2026-10-05 09:00:00']);
        Booking::factory()->create(['preferred_pickup_at' => '2026-10-06 09:00:00']);

        $generator = new BookingCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-05 14:00:00'));

        $this->assertSame('RS-261005-003', $code);
    }

    public function test_sequence_resets_for_a_different_date(): void
    {
        Booking::factory()->count(3)->create(['preferred_pickup_at' => '2026-10-05 09:00:00']);

        $generator = new BookingCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-06 09:00:00'));

        $this->assertSame('RS-261006-001', $code);
    }

    /**
     * Regression test: the generator used to find "the next number" by
     * parsing the most recently created booking's code, which broke once
     * that code wasn't RS-####-shaped (e.g. a walk-in's WB-YYMMDD-NNN code).
     * Counting rows instead of parsing a code string means an unrelated or
     * oddly shaped existing code can never corrupt the next one.
     */
    public function test_an_existing_booking_with_an_unrelated_code_shape_does_not_corrupt_the_next_code(): void
    {
        Booking::factory()->create([
            'code' => 'WEIRD-000999',
            'preferred_pickup_at' => '2026-10-05 09:00:00',
        ]);

        $generator = new BookingCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-05 14:00:00'));

        $this->assertSame('RS-261005-002', $code);
    }
}
