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
        return Booking::factory()->create(array_merge([
            'source_type' => 'shop_supplied',
            'status' => 'pending_confirmation',
        ], $attributes));
    }

    // --- start-cooking -----------------------------------------------------

    public function test_start_cooking_succeeds_from_confirmed_and_uses_the_longest_item(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'confirmed']);

        $shortService = Service::factory()->create(['est_minutes' => 30]);
        $longService = Service::factory()->create(['est_minutes' => 90]);

        BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $shortService->id]);
        BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $longService->id]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/start-cooking");

        $response->assertOk();
        $response->assertJsonPath('status', 'cooking');

        $booking->refresh();
        $this->assertSame('cooking', $booking->status);
        $this->assertNotNull($booking->cooking_started_at);
        $this->assertNotNull($booking->est_ready_at);
        $this->assertEqualsWithDelta(
            $booking->cooking_started_at->addMinutes(90)->timestamp,
            $booking->est_ready_at->timestamp,
            1
        );
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'cooking',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_start_cooking_from_a_shop_order_confirmed_also_succeeds(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->shopOrder(['status' => 'confirmed']);
        $service = Service::factory()->shopSupplied()->create(['est_minutes' => 45]);
        BookingItem::factory()->create(['booking_id' => $booking->id, 'service_id' => $service->id]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/start-cooking");

        $response->assertOk();
        $response->assertJsonPath('status', 'cooking');
    }

    public function test_start_cooking_from_another_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'approved']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/start-cooking");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);

        $booking->refresh();
        $this->assertSame('approved', $booking->status);
        $this->assertNull($booking->cooking_started_at);
    }

    // --- ready / out-for-delivery -------------------------------------------

    public function test_ready_succeeds_from_cooking_on_a_pickup_booking(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'cooking', 'fulfillment' => 'pickup']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/ready");

        $response->assertOk();
        $response->assertJsonPath('status', 'ready');
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'ready',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_ready_on_a_delivery_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'cooking', 'fulfillment' => 'delivery']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/ready");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);

        $booking->refresh();
        $this->assertSame('cooking', $booking->status);
    }

    public function test_out_for_delivery_succeeds_from_cooking_on_a_delivery_booking(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'cooking', 'fulfillment' => 'delivery']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/out-for-delivery");

        $response->assertOk();
        $response->assertJsonPath('status', 'out_for_delivery');
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'out_for_delivery',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_out_for_delivery_on_a_pickup_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'cooking', 'fulfillment' => 'pickup']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/out-for-delivery");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);

        $booking->refresh();
        $this->assertSame('cooking', $booking->status);
    }

    // --- complete ------------------------------------------------------------

    public function test_complete_succeeds_from_ready(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'ready', 'fulfillment' => 'pickup']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete");

        $response->assertOk();
        $response->assertJsonPath('status', 'completed');

        $booking->refresh();
        $this->assertSame('completed', $booking->status);
        $this->assertNotNull($booking->completed_at);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'completed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_complete_succeeds_from_out_for_delivery(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'out_for_delivery', 'fulfillment' => 'delivery']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete");

        $response->assertOk();
        $response->assertJsonPath('status', 'completed');

        $booking->refresh();
        $this->assertNotNull($booking->completed_at);
    }

    public function test_complete_from_an_earlier_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'cooking']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    // --- no-show ---------------------------------------------------------------

    public function test_no_show_succeeds_from_approved_on_a_customer_supplied_booking(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'approved']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show", [
            'remarks' => 'Did not drop off raw food',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'no_show');

        $booking->refresh();
        $this->assertSame('no_show', $booking->status);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'no_show',
            'changed_by' => $admin->id,
            'remarks' => 'Did not drop off raw food',
        ]);
    }

    public function test_no_show_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->shopOrder(['status' => 'confirmed']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_no_show_from_another_byo_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->booking(['status' => 'confirmed']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    // --- cancel ------------------------------------------------------------------

    public function test_cancel_succeeds_from_every_allowed_byo_status(): void
    {
        $this->loginAsSuperAdmin();

        foreach (['pending_review', 'approved', 'confirmed'] as $status) {
            $booking = $this->booking(['status' => $status]);

            $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel", [
                'remarks' => 'Customer requested cancellation',
            ]);

            $response->assertOk();
            $response->assertJsonPath('status', 'cancelled');
        }
    }

    public function test_cancel_succeeds_from_every_allowed_shop_status(): void
    {
        $this->loginAsSuperAdmin();

        foreach (['pending_confirmation', 'confirmed'] as $status) {
            $booking = $this->shopOrder(['status' => $status]);

            $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

            $response->assertOk();
            $response->assertJsonPath('status', 'cancelled');
        }
    }

    public function test_cancel_from_a_disallowed_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();

        foreach (['cooking', 'ready', 'out_for_delivery', 'completed', 'rejected', 'no_show', 'cancelled'] as $status) {
            $booking = $this->booking(['status' => $status]);

            $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

            $response->assertUnprocessable();
            $response->assertJsonValidationErrors(['status']);

            $booking->refresh();
            $this->assertSame($status, $booking->status);
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
        ]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $serviceB->id,
            'qty' => 1,
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $response->assertJsonPath('status', 'cancelled');

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
        $booking = $this->booking(['status' => 'confirmed']);
        $service = Service::factory()->create(['stock_qty' => 5]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'qty' => 2,
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
        $booking = $this->booking(['status' => 'confirmed']);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/start-cooking")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/ready")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/out-for-delivery")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel")->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/start-cooking")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/ready")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/out-for-delivery")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show")->assertForbidden();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel")->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $booking = $this->booking(['status' => 'confirmed']);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/start-cooking")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/ready")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/out-for-delivery")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/complete")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/no-show")->assertUnauthorized();
        $this->postJson("/api/v1/admin/bookings/{$booking->id}/cancel")->assertUnauthorized();
    }

    public function test_a_missing_or_non_numeric_id_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->postJson('/api/v1/admin/bookings/999999/start-cooking')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/start-cooking')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/ready')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/ready')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/out-for-delivery')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/out-for-delivery')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/complete')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/complete')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/no-show')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/no-show')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/999999/cancel')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/cancel')->assertNotFound();
    }
}
