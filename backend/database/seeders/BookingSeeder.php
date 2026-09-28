<?php

namespace Database\Seeders;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\InventoryLog;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\Booking\BookingCodeGenerator;
use App\Services\Booking\BookingStatusEngine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Demo bookings for exercising the admin bookings UI locally: every status,
 * both fulfillment methods, guest and account customers, both order flags,
 * and a split-status booking whose items diverge after Cooking. Not part of
 * DatabaseSeeder - run it explicitly:
 *
 *   php artisan db:seed --class=BookingSeeder
 */
class BookingSeeder extends Seeder
{
    private BookingStatusEngine $engine;

    private BookingCodeGenerator $codes;

    private User $admin;

    /** @var array<string, Service> */
    private array $services;

    public function run(): void
    {
        $this->engine = new BookingStatusEngine;
        $this->codes = new BookingCodeGenerator;

        DB::transaction(function () {
            $this->admin = User::where('role', 'super_admin')->first()
                ?? User::factory()->superAdmin()->create(['name' => 'Test Admin']);

            $this->services = $this->pickServices();

            $this->pendingReview();
            $this->awaitingWeighIn();
            $this->confirmedForDelivery();
            $this->cooking();
            $this->readyForPickup();
            $this->outForDelivery();
            $this->completedAndPaid();
            $this->rejected();
            $this->noShow();
            $this->cancelled();
            $this->shopOrderPendingConfirmation();
            $this->shopOrderCompletedWithDownpayment();
            $this->splitStatusDelivery();
        });
    }

    /**
     * Reuses whatever services already exist in this project (names and
     * rates vary per install) instead of inventing new ones, so seeded
     * bookings never clutter the real Services/Inventory list. Picks two
     * customer-supplied-only services, one shop-only service, and one dual
     * (both-capable) service, falling back to whatever's available when a
     * role has no exact match.
     *
     * @return array<string, Service>
     */
    private function pickServices(): array
    {
        $customerCapable = Service::where('allow_customer_supplied', true)->orderBy('id')->get();
        $shopCapable = Service::where('allow_shop_supplied', true)->orderBy('id')->get();

        if ($customerCapable->isEmpty() || $shopCapable->isEmpty()) {
            throw new RuntimeException(
                'BookingSeeder needs at least one customer-supplied and one shop-supplied service. '.
                'Seed or create services first (see DatabaseSeeder or the Services tab).'
            );
        }

        $customerOnly = $customerCapable->where('allow_shop_supplied', false)->values();
        $shopOnly = $shopCapable->where('allow_customer_supplied', false)->values();
        $dual = $customerCapable->firstWhere('allow_shop_supplied', true) ?? $shopCapable->first();

        $customerA = $customerOnly->get(0) ?? $customerCapable->first();
        $customerB = $customerOnly->get(1) ?? $customerOnly->get(0) ?? $customerCapable->first();
        $shopOnlyService = $shopOnly->first() ?? $shopCapable->firstWhere('id', '!=', $dual->id) ?? $shopCapable->first();

        return [
            'customerA' => $customerA,
            'customerB' => $customerB,
            'dual' => $dual,
            'shopOnly' => $shopOnlyService,
        ];
    }

    private function pendingReview(): void
    {
        $customer = $this->customer('Maria', 'Santos');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(9, 0),
            notes: 'Regular customer, bringing own pork belly.'
        );

        $item = $this->nonOrderItem($booking, $this->services['customerB'], 4.5);
        $this->advance($item, 'pending_review');

        $this->finalizeTotal($booking);
    }

    private function awaitingWeighIn(): void
    {
        $booking = $this->newBooking(
            customer: null,
            guestName: 'Pedro Reyes',
            guestPhone: $this->phone(),
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(14, 0),
        );
        $booking->dropoff_at = $this->today(8, 30);
        $booking->save();

        $item = $this->nonOrderItem($booking, $this->services['customerA'], 8.0);
        $this->advance($item, 'pending_review');

        $item->approved_at = $this->today(8, 45);
        $item->approved_by = $this->admin->id;
        $item->save();
        $this->advance($item, 'approved');

        $this->finalizeTotal($booking);
    }

    private function confirmedForDelivery(): void
    {
        $customer = $this->customer('Ana', 'Cruz');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: false,
            fulfillment: 'delivery',
            preferredPickupAt: $this->today(16, 0),
            deliveryAddress: '45 Mabini St, Quezon City',
            shippingFee: 100,
        );
        $booking->dropoff_at = $this->today(9, 0);
        $booking->save();

        $item = $this->nonOrderItem($booking, $this->services['dual'], 6.0);
        $this->advance($item, 'pending_review');

        $item->approved_at = $this->today(9, 15);
        $item->approved_by = $this->admin->id;
        $item->save();
        $this->advance($item, 'approved');

        $finalWeight = 6.2;
        $item->final_weight_kg = $finalWeight;
        $item->subtotal = round((float) $item->rate * $finalWeight, 2);
        $item->weighed_at = $this->today(9, 20);
        $item->confirmed_at = $this->today(9, 20);
        $item->confirmed_by = $this->admin->id;
        $item->save();
        $this->advance($item, 'confirmed');

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);
    }

    private function cooking(): void
    {
        $booking = $this->newBooking(
            customer: null,
            guestName: 'Ramon Dizon',
            guestPhone: $this->phone(),
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(15, 0),
        );
        $booking->dropoff_at = $this->today(10, 0);
        $booking->save();

        $item = $this->nonOrderItem($booking, $this->services['customerB'], 5.0);
        $this->walkNonOrderToConfirmed($item, $this->today(10, 10));

        $item->cooking_started_at = $this->today(10, 15);
        $item->est_ready_at = $this->today(10, 15)->addMinutes($item->est_minutes);
        $item->save();
        $this->advance($item, 'cooking');

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);
    }

    private function readyForPickup(): void
    {
        $customer = $this->customer('Carla', 'Mendoza');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(13, 0),
        );
        $booking->dropoff_at = $this->today(8, 0);
        $booking->save();

        $item = $this->nonOrderItem($booking, $this->services['customerA'], 7.0);
        $this->walkNonOrderToConfirmed($item, $this->today(8, 10));

        $item->cooking_started_at = $this->today(8, 15);
        $item->est_ready_at = $this->today(8, 15)->addMinutes($item->est_minutes);
        $item->save();
        $this->advance($item, 'cooking');
        $this->advance($item, 'ready');

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);
    }

    private function outForDelivery(): void
    {
        $booking = $this->newBooking(
            customer: null,
            guestName: 'Noel Tan',
            guestPhone: $this->phone(),
            isOrder: false,
            fulfillment: 'delivery',
            preferredPickupAt: $this->today(17, 0),
            deliveryAddress: '12 Aguinaldo Ave, Pasig City',
            shippingFee: 150,
        );
        $booking->dropoff_at = $this->today(11, 0);
        $booking->save();

        $item = $this->nonOrderItem($booking, $this->services['dual'], 5.5);
        $this->walkNonOrderToConfirmed($item, $this->today(11, 10));

        $item->cooking_started_at = $this->today(11, 15);
        $item->est_ready_at = $this->today(11, 15)->addMinutes($item->est_minutes);
        $item->save();
        $this->advance($item, 'cooking');
        $this->advance($item, 'out_for_delivery');

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);
    }

    private function completedAndPaid(): void
    {
        $customer = $this->customer('Liza', 'Fernandez');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(10, 0),
        );
        $booking->dropoff_at = $this->today(7, 0);
        $booking->save();

        $item = $this->nonOrderItem($booking, $this->services['customerB'], 6.0);
        $this->walkNonOrderToConfirmed($item, $this->today(7, 10));

        $item->cooking_started_at = $this->today(7, 15);
        $item->est_ready_at = $this->today(7, 15)->addMinutes($item->est_minutes);
        $item->save();
        $this->advance($item, 'cooking');
        $this->advance($item, 'ready');

        $item->completed_at = $this->today(9, 0);
        $item->save();
        $this->advance($item, 'completed');

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);

        Payment::create([
            'booking_id' => $booking->id,
            'type' => 'full',
            'amount' => $booking->total_amount,
            'method' => 'cash',
            'reference_no' => null,
            'status' => 'paid',
            'paid_at' => $this->today(9, 0),
            'recorded_by' => $this->admin->id,
        ]);
    }

    private function rejected(): void
    {
        $booking = $this->newBooking(
            customer: null,
            guestName: 'Boy Garcia',
            guestPhone: $this->phone(),
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(12, 0),
        );

        $item = $this->nonOrderItem($booking, $this->services['customerA'], 4.0);
        $this->advance($item, 'pending_review');

        $item->reject_reason = 'Item did not meet freshness standards.';
        $item->save();
        $this->advance($item, 'rejected', $item->reject_reason);

        $this->finalizeTotal($booking);
    }

    private function noShow(): void
    {
        $customer = $this->customer('Grace', 'Villanueva');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(11, 0),
        );

        $item = $this->nonOrderItem($booking, $this->services['dual'], 5.0);
        $this->advance($item, 'pending_review');

        $item->approved_at = $this->today(8, 0);
        $item->approved_by = $this->admin->id;
        $item->save();
        $this->advance($item, 'approved');
        $this->advance($item, 'no_show', 'Customer did not arrive for scheduled drop-off.');

        $this->finalizeTotal($booking);
    }

    private function cancelled(): void
    {
        $booking = $this->newBooking(
            customer: null,
            guestName: 'Jun Bautista',
            guestPhone: $this->phone(),
            isOrder: false,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(18, 0),
        );

        $item = $this->nonOrderItem($booking, $this->services['customerB'], 3.0);
        $this->advance($item, 'pending_review');
        $this->advance($item, 'cancelled', 'Customer cancelled by phone.');

        $this->finalizeTotal($booking);
    }

    private function shopOrderPendingConfirmation(): void
    {
        $customer = $this->customer('Wendy', 'Ocampo');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: true,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(13, 30),
        );

        $item = $this->orderItem($booking, $this->services['shopOnly'], 2);
        $this->advance($item, 'pending_confirmation');

        $this->finalizeTotal($booking);
    }

    private function shopOrderCompletedWithDownpayment(): void
    {
        $booking = $this->newBooking(
            customer: null,
            guestName: 'Rico Santos',
            guestPhone: $this->phone(),
            isOrder: true,
            fulfillment: 'pickup',
            preferredPickupAt: $this->today(12, 30),
        );

        $chicken = $this->orderItem($booking, $this->services['shopOnly'], 3);
        $turkey = $this->orderItem($booking, $this->services['dual'], 1);

        foreach ([$chicken, $turkey] as $item) {
            $this->advance($item, 'pending_confirmation');

            $item->confirmed_at = $this->today(9, 0);
            $item->confirmed_by = $this->admin->id;
            $item->save();
            $this->advance($item, 'confirmed');

            $item->cooking_started_at = $this->today(9, 5);
            $item->est_ready_at = $this->today(9, 5)->addMinutes($item->est_minutes);
            $item->save();
            $this->advance($item, 'cooking');
            $this->advance($item, 'ready');

            $item->completed_at = $this->today(11, 0);
            $item->save();
            $this->advance($item, 'completed');
        }

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);

        Payment::create([
            'booking_id' => $booking->id,
            'type' => 'downpayment',
            'amount' => round((float) $booking->total_amount * 0.5, 2),
            'method' => 'gcash',
            'reference_no' => 'GC-'.fake()->numerify('##########'),
            'status' => 'paid',
            'paid_at' => $this->today(9, 0),
            'recorded_by' => $this->admin->id,
        ]);
    }

    private function splitStatusDelivery(): void
    {
        $customer = $this->customer('Kevin', 'Alonzo');
        $booking = $this->newBooking(
            customer: $customer,
            guestName: null,
            guestPhone: null,
            isOrder: false,
            fulfillment: 'delivery',
            preferredPickupAt: $this->today(16, 30),
            deliveryAddress: '78 Bonifacio St, Makati City',
            shippingFee: 120,
        );
        $booking->dropoff_at = $this->today(9, 30);
        $booking->save();

        $stillCooking = $this->nonOrderItem($booking, $this->services['customerB'], 4.0);
        $outForDelivery = $this->nonOrderItem($booking, $this->services['customerA'], 9.0);

        foreach ([$stillCooking, $outForDelivery] as $item) {
            $this->walkNonOrderToConfirmed($item, $this->today(9, 45));

            $item->cooking_started_at = $this->today(9, 50);
            $item->est_ready_at = $this->today(9, 50)->addMinutes($item->est_minutes);
            $item->save();
            $this->advance($item, 'cooking');
        }

        $this->advance($outForDelivery, 'out_for_delivery');

        $this->finalizeTotal($booking, alsoSetTotalAmount: true);
    }

    private function customer(string $firstName, string $lastName): User
    {
        return User::factory()->create([
            'name' => "{$firstName} {$lastName}",
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $this->phone(),
        ]);
    }

    private function phone(): string
    {
        return fake()->unique()->numerify('09#########');
    }

    private function today(int $hour, int $minute): Carbon
    {
        return today()->setTime($hour, $minute);
    }

    private function newBooking(
        ?User $customer,
        ?string $guestName,
        ?string $guestPhone,
        bool $isOrder,
        string $fulfillment,
        Carbon $preferredPickupAt,
        ?string $deliveryAddress = null,
        float $shippingFee = 0,
        ?string $notes = null,
    ): Booking {
        return Booking::create([
            'code' => $this->codes->next($preferredPickupAt),
            'customer_id' => $customer?->id,
            'guest_name' => $guestName,
            'guest_phone' => $guestPhone,
            'is_order' => $isOrder,
            'fulfillment' => $fulfillment,
            'delivery_address' => $deliveryAddress,
            'shipping_fee' => $shippingFee,
            'preferred_dropoff_at' => $isOrder ? null : $preferredPickupAt->copy()->subHours(2),
            'preferred_pickup_at' => $preferredPickupAt,
            'estimated_total' => 0,
            'notes' => $notes,
        ]);
    }

    private function nonOrderItem(Booking $booking, Service $service, float $estWeightKg): BookingItem
    {
        $rate = (float) $service->roasting_rate_per_kg;
        $subtotal = round($rate * $estWeightKg, 2);

        $item = $booking->items()->create([
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => $estWeightKg,
            'rate' => $rate,
            'subtotal' => $subtotal,
            'est_minutes' => $service->est_minutes,
        ]);
        $item->setRelation('booking', $booking);

        return $item;
    }

    private function orderItem(Booking $booking, Service $service, int $qty): BookingItem
    {
        // Top up stock first so this demo reservation never goes negative,
        // regardless of what's already on hand; net inventory change is the
        // reservation itself, recorded below like a real shop order.
        Service::whereKey($service->id)->increment('stock_qty', $qty);

        $rate = (float) $service->shop_price;
        $subtotal = round($rate * $qty, 2);

        $item = $booking->items()->create([
            'service_id' => $service->id,
            'qty' => $qty,
            'rate' => $rate,
            'subtotal' => $subtotal,
            'est_minutes' => $service->est_minutes,
        ]);
        $item->setRelation('booking', $booking);

        Service::whereKey($service->id)->decrement('stock_qty', $qty);

        InventoryLog::create([
            'service_id' => $service->id,
            'change_qty' => -$qty,
            'reason' => 'reserve',
            'booking_id' => $booking->id,
            'created_by' => $this->admin->id,
        ]);

        return $item;
    }

    /**
     * Non-order items always pass through pending_review -> approved ->
     * confirmed before Cooking; scenarios that start further along share
     * this walk instead of repeating it.
     */
    private function walkNonOrderToConfirmed(BookingItem $item, Carbon $at): void
    {
        $this->advance($item, 'pending_review');

        $item->approved_at = $at;
        $item->approved_by = $this->admin->id;
        $item->save();
        $this->advance($item, 'approved');

        $item->final_weight_kg = $item->est_weight_kg;
        $item->weighed_at = $at;
        $item->confirmed_at = $at;
        $item->confirmed_by = $this->admin->id;
        $item->save();
        $this->advance($item, 'confirmed');
    }

    private function advance(BookingItem $item, string $to, ?string $remarks = null): void
    {
        $this->engine->transition($item, $to, $this->admin->id, $remarks);
    }

    private function finalizeTotal(Booking $booking, bool $alsoSetTotalAmount = false): void
    {
        $sum = round((float) $booking->items()->sum('subtotal'), 2);

        $booking->estimated_total = $sum;

        if ($alsoSetTotalAmount) {
            $booking->total_amount = $sum;
        }

        $booking->save();
    }
}
