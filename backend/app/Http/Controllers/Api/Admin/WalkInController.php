<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreWalkInRoastingRequest;
use App\Http\Requests\Admin\StoreWalkInShopOrderRequest;
use App\Http\Resources\AdminBookingResource;
use App\Models\Booking;
use App\Models\InventoryLog;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingCodeGenerator;
use App\Services\Booking\BookingStatusEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalkInController extends Controller
{
    public function searchCustomers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        $like = '%'.$validated['search'].'%';

        $customers = User::where('role', 'customer')
            ->where(function ($query) use ($like) {
                $query->where('phone', 'ilike', $like)
                    ->orWhereRaw("concat_ws(' ', first_name, last_name) ilike ?", [$like]);
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get(['id', 'first_name', 'last_name', 'phone']);

        return response()->json($customers->map(fn (User $customer) => [
            'id' => $customer->id,
            'name' => trim("{$customer->first_name} {$customer->last_name}"),
            'phone' => $customer->phone,
        ])->values());
    }

    public function storeRoasting(
        StoreWalkInRoastingRequest $request,
        BookingStatusEngine $statusEngine,
        BookingCodeGenerator $codeGenerator
    ): AdminBookingResource {
        $booking = DB::transaction(function () use ($request, $statusEngine, $codeGenerator) {
            $items = $request->validated('items');
            $services = Service::whereIn('id', array_column($items, 'service_id'))->get()->keyBy('id');

            $itemsData = [];
            $totalAmount = 0.0;

            foreach ($items as $item) {
                $service = $services[$item['service_id']];
                $rate = (float) $service->roasting_rate_per_kg;
                $finalWeightKg = (float) $item['final_weight_kg'];
                $subtotal = round($rate * $finalWeightKg, 2);
                $totalAmount += $subtotal;

                $itemsData[] = [
                    'service_id' => $service->id,
                    'qty' => 1,
                    'est_weight_kg' => $finalWeightKg,
                    'final_weight_kg' => $finalWeightKg,
                    'rate' => $rate,
                    'subtotal' => $subtotal,
                ];
            }

            $now = now();

            $booking = Booking::create([
                'code' => $codeGenerator->next(),
                'customer_id' => $request->validated('customer_id'),
                'guest_name' => $request->validated('guest_name'),
                'guest_phone' => $request->validated('guest_phone'),
                'source_type' => 'customer_supplied',
                'fulfillment' => $request->validated('fulfillment'),
                'delivery_address' => $request->validated('delivery_address'),
                'shipping_fee' => 0,
                'preferred_dropoff_at' => $now,
                'dropoff_at' => $now,
                'estimated_total' => round($totalAmount, 2),
                'total_amount' => round($totalAmount, 2),
                'notes' => $request->validated('notes'),
                'approved_at' => $now,
                'approved_by' => $request->user()->id,
                'weighed_at' => $now,
                'confirmed_at' => $now,
                'confirmed_by' => $request->user()->id,
            ]);

            foreach ($itemsData as $itemData) {
                $booking->items()->create($itemData);
            }

            $statusEngine->transition($booking, 'pending_review', $request->user()->id);
            $statusEngine->transition($booking, 'approved', $request->user()->id);
            $statusEngine->transition($booking, 'confirmed', $request->user()->id);

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    public function storeShop(
        StoreWalkInShopOrderRequest $request,
        BookingStatusEngine $statusEngine,
        BookingCodeGenerator $codeGenerator
    ): AdminBookingResource {
        $booking = DB::transaction(function () use ($request, $statusEngine, $codeGenerator) {
            $items = $request->validated('items');

            // Lock all distinct rows up front in a fixed order (ascending id) so a
            // walk-in sale and a concurrent customer order or admin cancel never
            // lock them in opposite order and deadlock.
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

            $now = now();

            $booking = Booking::create([
                'code' => $codeGenerator->next(),
                'customer_id' => $request->validated('customer_id'),
                'guest_name' => $request->validated('guest_name'),
                'guest_phone' => $request->validated('guest_phone'),
                'source_type' => 'shop_supplied',
                'fulfillment' => $request->validated('fulfillment'),
                'delivery_address' => $request->validated('delivery_address'),
                'shipping_fee' => 0,
                'estimated_total' => round($totalAmount, 2),
                'total_amount' => round($totalAmount, 2),
                'notes' => $request->validated('notes'),
                'confirmed_at' => $now,
                'confirmed_by' => $request->user()->id,
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
                    'created_by' => $request->user()->id,
                ]);
            }

            $statusEngine->transition($booking, 'pending_confirmation', $request->user()->id);
            $statusEngine->transition($booking, 'confirmed', $request->user()->id);

            return $booking;
        });

        return new AdminBookingResource($booking->load(self::detailEagerLoads()));
    }

    /**
     * @return array<int|string, string|\Closure>
     */
    private static function detailEagerLoads(): array
    {
        return [
            'items.service',
            'customer',
            'latestStatusLog',
            'statusLogs' => fn ($query) => $query->orderBy('id')->with('changer'),
            'approver',
            'confirmer',
        ];
    }
}
