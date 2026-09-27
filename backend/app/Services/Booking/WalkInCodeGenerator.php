<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

/**
 * Generates the `WB-YYMMDD-NNN` walk-in booking code: YYMMDD is the booking's
 * pickup/delivery date, NNN is a per-date sequence (bookings already
 * scheduled for that date, plus this one). Must run inside the caller's
 * transaction: like `BookingCodeGenerator`, a plain count isn't
 * self-serializing under concurrency, so this takes its own
 * transaction-scoped Postgres advisory lock first.
 */
class WalkInCodeGenerator
{
    private const LOCK_KEY = 918273646;

    public function next(CarbonInterface|string $preferredPickupAt): string
    {
        $date = Carbon::parse($preferredPickupAt);

        DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);

        $sequence = Booking::whereDate('preferred_pickup_at', $date->toDateString())->count() + 1;

        return 'WB-'.$date->format('ymd').'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
