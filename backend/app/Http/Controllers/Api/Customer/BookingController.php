<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Service;
use App\Services\Booking\BookingStatusEngine;
use Illuminate\Support\Facades\DB;

class BookingController extends Controller
{
    /** Arbitrary fixed key for the booking-code advisory lock (see Notes in the spec). */
    private const CODE_LOCK_KEY = 918273645;

    public function store(StoreBookingRequest $request, BookingStatusEngine $statusEngine): BookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine) {
            $items = $request->validated('items');
            $services = Service::whereIn('id', array_column($items, 'service_id'))->get()->keyBy('id');

            $itemsData = [];
            $estimatedTotal = 0.0;

            foreach ($items as $item) {
                $service = $services[$item['service_id']];
                $rate = (float) $service->roasting_rate_per_kg;
                $subtotal = round($rate * (float) $item['est_weight_kg'], 2);
                $estimatedTotal += $subtotal;

                $itemsData[] = [
                    'service_id' => $service->id,
                    'qty' => 1,
                    'est_weight_kg' => $item['est_weight_kg'],
                    'rate' => $rate,
                    'subtotal' => $subtotal,
                ];
            }

            DB::select('select pg_advisory_xact_lock(?)', [self::CODE_LOCK_KEY]);
            $lastCode = Booking::orderByDesc('id')->value('code');
            $nextNumber = $lastCode ? ((int) substr($lastCode, 3)) + 1 : 1;

            $booking = Booking::create([
                'code' => 'RS-'.str_pad((string) $nextNumber, 4, '0', STR_PAD_LEFT),
                'customer_id' => $request->user()->id,
                'source_type' => 'customer_supplied',
                'fulfillment' => $request->validated('fulfillment'),
                'delivery_address' => $request->validated('delivery_address'),
                'shipping_fee' => 0,
                'preferred_dropoff_at' => $request->validated('preferred_dropoff_at'),
                'estimated_total' => round($estimatedTotal, 2),
                'notes' => $request->validated('notes'),
            ]);

            foreach ($itemsData as $itemData) {
                $booking->items()->create($itemData);
            }

            $statusEngine->transition($booking, $statusEngine->initialStatusFor('customer_supplied'));

            return $booking;
        });

        return new BookingResource($booking->load('items.service'));
    }
}
