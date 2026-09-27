<?php

namespace Tests\Feature\Booking;

use App\Models\Booking;
use App\Services\Booking\WalkInCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WalkInCodeGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_code_for_a_date_is_sequence_one(): void
    {
        $generator = new WalkInCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-05 11:30:00'));

        $this->assertSame('WB-261005-001', $code);
    }

    public function test_sequence_increments_for_existing_bookings_on_the_same_date(): void
    {
        Booking::factory()->count(2)->create(['preferred_pickup_at' => '2026-10-05 09:00:00']);
        Booking::factory()->create(['preferred_pickup_at' => '2026-10-06 09:00:00']);

        $generator = new WalkInCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-05 14:00:00'));

        $this->assertSame('WB-261005-003', $code);
    }

    public function test_sequence_resets_for_a_different_date(): void
    {
        Booking::factory()->count(3)->create(['preferred_pickup_at' => '2026-10-05 09:00:00']);

        $generator = new WalkInCodeGenerator;

        $code = DB::transaction(fn () => $generator->next('2026-10-06 09:00:00'));

        $this->assertSame('WB-261006-001', $code);
    }
}
