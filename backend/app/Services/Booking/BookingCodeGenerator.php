<?php

namespace App\Services\Booking;

use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Generates the next `RS-####` booking code. One shared sequence across both
 * booking types, since `bookings.code` is one unique column regardless of
 * `source_type`. Must run inside the caller's transaction: the advisory lock
 * is transaction-scoped and releases automatically at commit/rollback.
 *
 * A plain `lockForUpdate()` on the last row would not actually serialize two
 * concurrent creators (neither request updates that row, so both could read
 * the same "last" value in sequence), so this uses a Postgres advisory lock
 * instead.
 */
class BookingCodeGenerator
{
    private const LOCK_KEY = 918273645;

    public function next(): string
    {
        DB::select('select pg_advisory_xact_lock(?)', [self::LOCK_KEY]);
        $lastCode = Booking::orderByDesc('id')->value('code');
        $nextNumber = $lastCode ? ((int) substr($lastCode, 3)) + 1 : 1;

        return 'RS-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT);
    }
}
