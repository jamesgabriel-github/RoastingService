<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingStatusLog;
use InvalidArgumentException;

/**
 * The single place that knows which booking-status moves are allowed and
 * writes the audit trail for each one. `null` as a from-status means
 * "booking creation". Both order flags share one status column, so a
 * non-order booking never reaches an order-only status and vice versa.
 */
class BookingStatusEngine
{
    /**
     * @var array<int, array<string|null, list<string>>>
     */
    private const TRANSITIONS = [
        0 => [
            null => ['pending_review'],
            'pending_review' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['confirmed', 'cancelled', 'no_show'],
            'confirmed' => ['cooking', 'cancelled'],
            'cooking' => ['ready', 'out_for_delivery'],
            'ready' => ['completed'],
            'out_for_delivery' => ['completed'],
        ],
        1 => [
            null => ['pending_confirmation'],
            'pending_confirmation' => ['confirmed', 'rejected', 'cancelled'],
            'confirmed' => ['cooking', 'cancelled'],
            'cooking' => ['ready', 'out_for_delivery'],
            'ready' => ['completed'],
            'out_for_delivery' => ['completed'],
        ],
    ];

    public function initialStatusFor(bool $isOrder): string
    {
        return self::TRANSITIONS[$isOrder][null][0];
    }

    public function isAllowed(bool $isOrder, ?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$isOrder][$from] ?? [], true);
    }

    /**
     * Move `$booking` to `$to`, persist it, and record the status log entry.
     * Expected to run inside the caller's transaction.
     */
    public function transition(Booking $booking, string $to, ?int $changedBy = null, ?string $remarks = null): void
    {
        if (! $this->isAllowed($booking->is_order, $booking->status, $to)) {
            $from = $booking->status ?? 'creation';
            $label = $booking->is_order ? 'order' : 'non-order';

            throw new InvalidArgumentException(
                "Cannot move a {$label} booking from {$from} to {$to}."
            );
        }

        $booking->status = $to;
        $booking->save();

        BookingStatusLog::create([
            'booking_id' => $booking->id,
            'status' => $to,
            'changed_by' => $changedBy,
            'remarks' => $remarks,
        ]);
    }
}
