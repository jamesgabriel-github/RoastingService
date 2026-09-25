<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StorePaymentRequest;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingPaymentController extends Controller
{
    /** @var list<string> */
    private const PAYMENT_ELIGIBLE_STATUSES = [
        'confirmed',
        'cooking',
        'ready',
        'out_for_delivery',
        'completed',
    ];

    public function store(StorePaymentRequest $request, int $id): AdminBookingResource
    {
        $booking = DB::transaction(function () use ($request, $id) {
            $booking = Booking::lockForUpdate()->findOrFail($id);

            if (! in_array($booking->status, self::PAYMENT_ELIGIBLE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'status' => 'This booking cannot accept payment right now.',
                ]);
            }

            if ($booking->payments()->where('status', 'paid')->exists()) {
                throw ValidationException::withMessages([
                    'amount' => 'This booking is already fully paid.',
                ]);
            }

            Payment::create([
                'booking_id' => $booking->id,
                'type' => 'full',
                'amount' => $booking->total_amount,
                'method' => $request->validated('method'),
                'reference_no' => $request->validated('reference_no'),
                'status' => 'paid',
                'paid_at' => now(),
                'recorded_by' => $request->user()->id,
            ]);

            return $booking;
        });

        return new AdminBookingResource($booking->load(BookingController::detailEagerLoads()));
    }
}
