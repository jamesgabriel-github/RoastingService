<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\StoreShopOrderRequest;
use App\Http\Resources\BookingResource;
use App\Models\Booking;
use App\Models\InventoryLog;
use App\Models\Service;
use App\Services\Booking\BookingCodeGenerator;
use App\Services\Booking\BookingStatusEngine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function store(
        StoreShopOrderRequest $request,
        BookingStatusEngine $statusEngine,
        BookingCodeGenerator $codeGenerator
    ): BookingResource {
        $booking = DB::transaction(function () use ($request, $statusEngine, $codeGenerator) {
            $items = $request->validated('items');
            $customerId = $request->user()->id;

            // Lock all distinct rows up front in a fixed order (ascending id) so two
            // concurrent orders listing the same services never lock them in opposite
            // order and deadlock.
            $serviceIds = collect($items)->pluck('service_id')->unique()->values();
            $services = Service::where('allow_shop_supplied', true)
                ->where('is_active', true)
                ->whereIn('id', $serviceIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $itemsData = [];
            $totalAmount = 0.0;

            foreach ($items as $index => $item) {
                $service = $services->get($item['service_id']);
                $qty = $item['qty'];

                if (! $service || $service->stock_qty < $qty) {
                    throw ValidationException::withMessages([
                        "items.{$index}.qty" => 'Not enough stock is available for this item.',
                    ]);
                }

                $service->stock_qty -= $qty;

                $rate = (float) $service->shop_price;
                $subtotal = round($rate * $qty, 2);
                $totalAmount += $subtotal;

                $itemsData[] = [
                    'service_id' => $service->id,
                    'qty' => $qty,
                    'rate' => $rate,
                    'subtotal' => $subtotal,
                    'inventory_change_qty' => -$qty,
                ];
            }

            foreach ($services as $service) {
                $service->save();
            }

            $booking = Booking::create([
                'code' => $codeGenerator->next(),
                'customer_id' => $customerId,
                'source_type' => 'shop_supplied',
                'fulfillment' => $request->validated('fulfillment'),
                'delivery_address' => $request->validated('delivery_address'),
                'shipping_fee' => 0,
                'estimated_total' => 0,
                'total_amount' => round($totalAmount, 2),
                'notes' => $request->validated('notes'),
            ]);

            foreach ($itemsData as $itemData) {
                $booking->items()->create([
                    'service_id' => $itemData['service_id'],
                    'qty' => $itemData['qty'],
                    'rate' => $itemData['rate'],
                    'subtotal' => $itemData['subtotal'],
                ]);

                InventoryLog::create([
                    'service_id' => $itemData['service_id'],
                    'change_qty' => $itemData['inventory_change_qty'],
                    'reason' => 'reserve',
                    'booking_id' => $booking->id,
                    'created_by' => $customerId,
                ]);
            }

            $statusEngine->transition($booking, $statusEngine->initialStatusFor('shop_supplied'));

            return $booking;
        });

        return new BookingResource($booking->load('items.service'));
    }
}
