<?php

namespace Tests\Unit\Booking;

use App\Services\Booking\BookingStatusEngine;
use PHPUnit\Framework\TestCase;

class BookingStatusEngineTest extends TestCase
{
    public function test_initial_status_for_customer_supplied_is_pending_review(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertSame('pending_review', $engine->initialStatusFor(false));
    }

    public function test_initial_status_for_shop_supplied_is_pending_confirmation(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertSame('pending_confirmation', $engine->initialStatusFor(true));
    }

    public function test_creation_transition_is_allowed(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertTrue($engine->isAllowed(false, null, 'pending_review'));
    }

    public function test_skipping_a_status_is_not_allowed(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertFalse($engine->isAllowed(false, 'pending_review', 'cooking'));
    }

    public function test_a_shop_supplied_only_status_is_not_reachable_by_a_customer_supplied_booking(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertFalse($engine->isAllowed(false, null, 'pending_confirmation'));
    }

    public function test_completed_has_no_further_transitions(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertFalse($engine->isAllowed(false, 'completed', 'cooking'));
    }

    public function test_a_customer_supplied_booking_can_be_cancelled_while_pending_review(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertTrue($engine->isAllowed(false, 'pending_review', 'cancelled'));
    }

    public function test_a_shop_supplied_booking_can_be_cancelled_while_pending_confirmation(): void
    {
        $engine = new BookingStatusEngine;

        $this->assertTrue($engine->isAllowed(true, 'pending_confirmation', 'cancelled'));
    }
}
