<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function loginAsSuperAdmin(): User
    {
        $superAdmin = User::factory()->superAdmin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $superAdmin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $superAdmin;
    }

    private function loginAsAdminWithoutBookingsPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    private function booking(array $attributes = []): Booking
    {
        return Booking::factory()->create($attributes);
    }

    private function shopOrder(array $attributes = []): Booking
    {
        return Booking::factory()->create(array_merge(['is_order' => true], $attributes));
    }

    /**
     * Create a booking with a single item at `$status`.
     */
    private function itemWithStatus(string $status, array $bookingAttributes = [], array $itemAttributes = []): BookingItem
    {
        $booking = $this->booking($bookingAttributes);
        $service = Service::factory()->create();

        return $booking->items()->create(array_merge([
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => $status,
        ], $itemAttributes));
    }

    // --- start-cooking -----------------------------------------------------

    public function test_start_cooking_succeeds_from_confirmed_and_uses_the_items_own_service(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking();

        $shortService = Service::factory()->create(['est_minutes' => 30]);
        $longService = Service::factory()->create(['est_minutes' => 90]);

        BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $shortService->id, 'status' => 'confirmed']);
        $longItem = BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $longService->id, 'status' => 'confirmed']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$longItem->id}/start-cooking");

        $response->assertOk();
        $respondedItem = collect($response->json('items'))->firstWhere('id', $longItem->id);
        $this->assertSame('cooking', $respondedItem['status']);

        $longItem->refresh();
        $this->assertSame('cooking', $longItem->status);
        $this->assertNotNull($longItem->cooking_started_at);
        $this->assertNotNull($longItem->est_ready_at);
        $this->assertEqualsWithDelta(
            $longItem->cooking_started_at->addMinutes(90)->timestamp,
            $longItem->est_ready_at->timestamp,
            1
        );
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $longItem->id,
            'status' => 'cooking',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_start_cooking_from_a_shop_order_confirmed_also_succeeds(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->shopOrder();
        $service = Service::factory()->shopSupplied()->create(['est_minutes' => 45]);
        $item = BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $service->id, 'status' => 'confirmed']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/start-cooking");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'cooking');
    }

    public function test_start_cooking_from_another_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('approved');

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/start-cooking");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);

        $item->refresh();
        $this->assertSame('approved', $item->status);
        $this->assertNull($item->cooking_started_at);
    }

    public function test_two_items_on_the_same_booking_can_be_at_different_fulfillment_stages(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking(['fulfillment' => 'pickup']);
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();

        $itemA = BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $serviceA->id, 'status' => 'confirmed']);
        $itemB = BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $serviceB->id, 'status' => 'confirmed']);

        $this->postJson("/api/v1/admin/booking-items/{$itemA->id}/start-cooking")->assertOk();

        $itemA->refresh();
        $itemB->refresh();
        $this->assertSame('cooking', $itemA->status);
        $this->assertSame('confirmed', $itemB->status);

        $response = $this->postJson("/api/v1/admin/booking-items/{$itemA->id}/ready");
        $response->assertOk();

        $itemA->refresh();
        $itemB->refresh();
        $this->assertSame('ready', $itemA->status);
        $this->assertSame('confirmed', $itemB->status);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $itemA->id,
            'status' => 'ready',
            'changed_by' => $admin->id,
        ]);
    }

    // --- ready / out-for-delivery -------------------------------------------

    public function test_ready_succeeds_from_cooking_on_a_pickup_booking(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('cooking', ['fulfillment' => 'pickup']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/ready");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'ready');
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $item->booking_id,
            'booking_item_id' => $item->id,
            'status' => 'ready',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_ready_on_a_delivery_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('cooking', ['fulfillment' => 'delivery', 'delivery_address' => '123 Test St']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/ready");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);

        $item->refresh();
        $this->assertSame('cooking', $item->status);
    }

    public function test_out_for_delivery_succeeds_from_cooking_on_a_delivery_booking(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('cooking', ['fulfillment' => 'delivery', 'delivery_address' => '123 Test St']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/out-for-delivery");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'out_for_delivery');
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $item->booking_id,
            'booking_item_id' => $item->id,
            'status' => 'out_for_delivery',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_out_for_delivery_on_a_pickup_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('cooking', ['fulfillment' => 'pickup']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/out-for-delivery");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);

        $item->refresh();
        $this->assertSame('cooking', $item->status);
    }

    // --- complete ------------------------------------------------------------

    public function test_complete_succeeds_from_ready(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('ready', ['fulfillment' => 'pickup']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/complete");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'completed');

        $item->refresh();
        $this->assertSame('completed', $item->status);
        $this->assertNotNull($item->completed_at);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $item->booking_id,
            'booking_item_id' => $item->id,
            'status' => 'completed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_complete_succeeds_from_out_for_delivery(): void
    {
        $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('out_for_delivery', ['fulfillment' => 'delivery', 'delivery_address' => '123 Test St']);

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/complete");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'completed');

        $item->refresh();
        $this->assertNotNull($item->completed_at);
    }

    public function test_complete_from_an_earlier_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('cooking');

        $response = $this->postJson("/api/v1/admin/booking-items/{$item->id}/complete");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    // --- no-show ---------------------------------------------------------------

    public function test_no_show_succeeds_from_approved_on_a_customer_supplied_booking(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking();
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'approved',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show", [
            'remarks' => 'Did not drop off raw food',
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'no_show');

        $item = $booking->items->first()->fresh();
        $this->assertSame('no_show', $item->status);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'status' => 'no_show',
            'changed_by' => $admin->id,
            'remarks' => 'Did not drop off raw food',
        ]);
    }

    public function test_no_show_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->shopOrder();
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_no_show_from_another_byo_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking();
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    // --- cancel ------------------------------------------------------------------

    public function test_cancel_succeeds_from_every_allowed_byo_status(): void
    {
        $this->loginAsSuperAdmin();

        foreach (['pending_review', 'approved', 'confirmed'] as $status) {
            $booking = $this->booking();
            $booking->items()->create([
                'service_id' => Service::factory()->create()->id,
                'qty' => 1,
                'rate' => 150,
                'subtotal' => 450,
                'status' => $status,
            ]);

            $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel", [
                'remarks' => 'Customer requested cancellation',
            ]);

            $response->assertOk();
            $response->assertJsonPath('items.0.status', 'cancelled');
        }
    }

    public function test_cancel_succeeds_from_every_allowed_shop_status(): void
    {
        $this->loginAsSuperAdmin();

        foreach (['pending_confirmation', 'confirmed'] as $status) {
            $booking = $this->shopOrder();
            $booking->items()->create([
                'service_id' => Service::factory()->create()->id,
                'qty' => 1,
                'rate' => 150,
                'subtotal' => 450,
                'status' => $status,
            ]);

            $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

            $response->assertOk();
            $response->assertJsonPath('items.0.status', 'cancelled');
        }
    }

    public function test_cancel_from_a_disallowed_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();

        foreach (['cooking', 'ready', 'out_for_delivery', 'completed', 'rejected', 'no_show', 'cancelled'] as $status) {
            $booking = $this->booking();
            $item = $booking->items()->create([
                'service_id' => Service::factory()->create()->id,
                'qty' => 1,
                'rate' => 150,
                'subtotal' => 450,
                'status' => $status,
            ]);

            $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

            $response->assertUnprocessable();
            $response->assertJsonValidationErrors(['status']);

            $item->refresh();
            $this->assertSame($status, $item->status);
        }
    }

    public function test_cancel_on_a_shop_supplied_booking_releases_reserved_stock(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->shopOrder();

        $serviceA = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);
        $serviceB = Service::factory()->shopSupplied()->create(['stock_qty' => 3]);

        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $serviceA->id,
            'qty' => 2,
            'status' => 'confirmed',
        ]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $serviceB->id,
            'qty' => 1,
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'cancelled');

        $this->assertSame(12, $serviceA->fresh()->stock_qty);
        $this->assertSame(4, $serviceB->fresh()->stock_qty);

        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $serviceA->id,
            'booking_id' => $booking->id,
            'reason' => 'release',
            'change_qty' => 2,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $serviceB->id,
            'booking_id' => $booking->id,
            'reason' => 'release',
            'change_qty' => 1,
            'created_by' => $admin->id,
        ]);
    }

    public function test_cancel_on_a_customer_supplied_booking_leaves_stock_and_inventory_logs_untouched(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking();
        $service = Service::factory()->create(['stock_qty' => 5]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'qty' => 2,
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $this->assertSame(5, $service->fresh()->stock_qty);
        $this->assertDatabaseMissing('inventory_logs', [
            'service_id' => $service->id,
            'booking_id' => $booking->id,
        ]);
    }

    // --- permissions / auth / not found -----------------------------------------

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $item = $this->itemWithStatus('confirmed');
        $booking = $item->booking;

        $this->postJson("/api/v1/admin/booking-items/{$item->id}/start-cooking")->assertForbidden();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/ready")->assertForbidden();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/out-for-delivery")->assertForbidden();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/complete")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel")->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $item = $this->itemWithStatus('confirmed');
        $booking = $item->booking;
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->postJson("/api/v1/admin/booking-items/{$item->id}/start-cooking")->assertForbidden();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/ready")->assertForbidden();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/out-for-delivery")->assertForbidden();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/complete")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel")->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $item = $this->itemWithStatus('confirmed');
        $booking = $item->booking;

        $this->postJson("/api/v1/admin/booking-items/{$item->id}/start-cooking")->assertUnauthorized();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/ready")->assertUnauthorized();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/out-for-delivery")->assertUnauthorized();
        $this->postJson("/api/v1/admin/booking-items/{$item->id}/complete")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel")->assertUnauthorized();
    }

    public function test_a_missing_or_non_numeric_id_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->postJson('/api/v1/admin/booking-items/999999/start-cooking')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/abc/start-cooking')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/999999/ready')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/abc/ready')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/999999/out-for-delivery')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/abc/out-for-delivery')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/999999/complete')->assertNotFound();
        $this->postJson('/api/v1/admin/booking-items/abc/complete')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/no-show')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/no-show')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/cancel')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/cancel')->assertNotFound();
    }
}
