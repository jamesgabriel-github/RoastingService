<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreBookingRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\InventoryLog;
use App\Models\Service;
use App\Services\Booking\BookingCodeGenerator;
use App\Services\Booking\BookingStatusEngine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return BookingResource::collection(
            Booking::where('customer_id', $request->user()->id)
                ->with('items.service')
                ->orderByDesc('id')
                ->get()
        );
    }

    public function show(Request $request, int $id): BookingResource
    {
        $booking = Booking::where('customer_id', $request->user()->id)
            ->with([
                'items.service',
                'statusLogs' => fn ($query) => $query->orderBy('id')->with(['changer', 'item.service']),
            ])
            ->findOrFail($id);

        return new BookingResource($booking);
    }

    public function cancel(Request $request, BookingStatusEngine $statusEngine, int $id): BookingResource
    {
        $booking = DB::transaction(function () use ($request, $statusEngine, $id) {
            $booking = Booking::where('customer_id', $request->user()->id)
                ->with('items')
                ->lockForUpdate()
                ->findOrFail($id);

            $commonStatus = $booking->commonStatus();

            if ($commonStatus === null || ! $statusEngine->isAllowed($booking->is_order, $commonStatus, 'cancelled')) {
                throw ValidationException::withMessages([
                    'status' => 'This booking can no longer be cancelled.',
                ]);
            }

            if ($booking->is_order) {
                // Mutate rows in a fixed ascending id order, matching OrderController's
                // reservation lock order, so a cancel and a concurrent order can't deadlock.
                foreach ($booking->items->sortBy('service_id') as $item) {
                    Service::where('id', $item->service_id)->increment('stock_qty', $item->qty);

                    InventoryLog::create([
                        'service_id' => $item->service_id,
                        'change_qty' => $item->qty,
                        'reason' => 'release',
                        'booking_id' => $booking->id,
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            foreach ($booking->items as $item) {
                $item->setRelation('booking', $booking);
                $statusEngine->transition($item, 'cancelled');
            }

            return $booking;
        });

        return new BookingResource(
            $booking->load([
                'items.service',
                'statusLogs' => fn ($query) => $query->orderBy('id')->with(['changer', 'item.service']),
            ])
        );
    }

    public function store(
        StoreBookingRequest $request,
        BookingStatusEngine $statusEngine,
        BookingCodeGenerator $codeGenerator
    ): BookingResource {
        $booking = DB::transaction(function () use ($request, $statusEngine, $codeGenerator) {
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

            $booking = Booking::create([
                'code' => $codeGenerator->next(),
                'customer_id' => $request->user()->id,
                'is_order' => false,
                'fulfillment' => $request->validated('fulfillment'),
                'delivery_address' => $request->validated('delivery_address'),
                'shipping_fee' => 0,
                'preferred_dropoff_at' => $request->validated('preferred_dropoff_at'),
                'preferred_pickup_at' => $request->validated('preferred_pickup_at'),
                'estimated_total' => round($estimatedTotal, 2),
                'notes' => $request->validated('notes'),
            ]);

            $initialStatus = $statusEngine->initialStatusFor(false);

            foreach ($itemsData as $itemData) {
                $item = $booking->items()->create($itemData);
                $item->setRelation('booking', $booking);
                $statusEngine->transition($item, $initialStatus);
            }

            return $booking;
        });

        return new BookingResource($booking->load('items.service'));
    }
}
