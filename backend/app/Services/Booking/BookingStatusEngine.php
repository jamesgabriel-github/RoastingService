<?php

namespace App\Services\Booking;

use App\Models\Booking;
use App\Models\BookingStatusLog;
use InvalidArgumentException;

/**
 * The single place that knows which booking-status moves are allowed and
 * writes the audit trail for each one. `null` as a from-status means
 * "booking creation". Both source types share one status column, so a
 * customer-supplied booking never reaches a shop-supplied-only status and
 * vice versa.
 */
class BookingStatusEngine
{
    /**
     * @var array<string, array<string|null, list<string>>>
     */
    private const TRANSITIONS = [
        'customer_supplied' => [
            null => ['pending_review'],
            'pending_review' => ['approved', 'rejected', 'cancelled'],
            'approved' => ['confirmed', 'cancelled', 'no_show'],
            'confirmed' => ['cooking', 'cancelled'],
            'cooking' => ['ready', 'out_for_delivery'],
            'ready' => ['completed'],
            'out_for_delivery' => ['completed'],
        ],
        'shop_supplied' => [
            null => ['pending_confirmation'],
            'pending_confirmation' => ['confirmed', 'rejected', 'cancelled'],
            'confirmed' => ['cooking', 'cancelled'],
            'cooking' => ['ready', 'out_for_delivery'],
            'ready' => ['completed'],
            'out_for_delivery' => ['completed'],
        ],
    ];

    public function initialStatusFor(string $sourceType): string
    {
        $initial = self::TRANSITIONS[$sourceType][null] ?? null;

        if ($initial === null) {
            throw new InvalidArgumentException("Unknown booking source type: {$sourceType}");
        }

        return $initial[0];
    }

    public function isAllowed(string $sourceType, ?string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$sourceType][$from] ?? [], true);
    }

    /**
     * Move `$booking` to `$to`, persist it, and record the status log entry.
     * Expected to run inside the caller's transaction.
     */
    public function transition(Booking $booking, string $to, ?int $changedBy = null, ?string $remarks = null): void
    {
        if (! $this->isAllowed($booking->source_type, $booking->status, $to)) {
            $from = $booking->status ?? 'creation';

            throw new InvalidArgumentException(
                "Cannot move a {$booking->source_type} booking from {$from} to {$to}."
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
