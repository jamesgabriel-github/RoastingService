<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\BookingItem;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminOrderConfirmationTest extends TestCase
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

    private function shopOrder(array $attributes = [], string $itemStatus = 'pending_confirmation'): Booking
    {
        $booking = Booking::factory()->create(array_merge(['is_order' => true], $attributes));
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 150,
            'status' => $itemStatus,
        ]);

        return $booking->load('items');
    }

    public function test_confirm_succeeds_from_pending_confirmation(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->shopOrder();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/confirm-order");

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'confirmed');
        $response->assertJsonPath('items.0.confirmed_by_name', $admin->name);

        $item = $booking->items->first()->fresh();
        $this->assertSame('confirmed', $item->status);
        $this->assertSame($admin->id, $item->confirmed_by);
        $this->assertNotNull($item->confirmed_at);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'status' => 'confirmed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_confirm_on_a_booking_not_in_pending_confirmation_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->shopOrder([], 'confirmed');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/confirm-order");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_confirm_on_a_customer_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['is_order' => false]);
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 150,
            'status' => 'approved',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/confirm-order");

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_reject_succeeds_and_releases_reserved_stock(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['is_order' => true]);

        $serviceA = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);
        $serviceB = Service::factory()->shopSupplied()->create(['stock_qty' => 3]);

        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $serviceA->id,
            'qty' => 2,
            'status' => 'pending_confirmation',
        ]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $serviceB->id,
            'qty' => 1,
            'status' => 'pending_confirmation',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", [
            'reason' => 'Out of stock elsewhere',
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'rejected');
        $response->assertJsonPath('items.0.reject_reason', 'Out of stock elsewhere');

        $items = $booking->items()->get();
        $this->assertTrue($items->every(fn (BookingItem $item) => $item->status === 'rejected'));
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'rejected',
            'changed_by' => $admin->id,
            'remarks' => 'Out of stock elsewhere',
        ]);

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

    public function test_reject_on_a_booking_not_in_pending_confirmation_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['is_order' => true]);
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'qty' => 2,
            'status' => 'confirmed',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", [
            'reason' => 'Too late',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $this->assertSame(5, $service->fresh()->stock_qty);
    }

    public function test_reject_on_a_customer_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['is_order' => false]);
        $service = Service::factory()->create(['stock_qty' => 5]);
        BookingItem::factory()->create([
            'booking_id' => $booking->id,
            'service_id' => $service->id,
            'qty' => 2,
            'status' => 'pending_review',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", [
            'reason' => 'Not a shop order',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $this->assertSame(5, $service->fresh()->stock_qty);
    }

    public function test_a_missing_reason_on_reject_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->shopOrder();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $booking = $this->shopOrder();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/confirm-order")->assertForbidden();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", [
            'reason' => 'No permission',
        ])->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $booking = $this->shopOrder();
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/confirm-order")->assertForbidden();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", [
            'reason' => 'No permission',
        ])->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $booking = $this->shopOrder();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/confirm-order")->assertUnauthorized();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject-order", [
            'reason' => 'No permission',
        ])->assertUnauthorized();
    }

    public function test_a_missing_or_non_numeric_id_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->postJson('/api/v1/admin/bookings/999999/confirm-order')->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/confirm-order')->assertNotFound();

        $this->postJson('/api/v1/admin/bookings/999999/reject-order', [
            'reason' => 'No booking',
        ])->assertNotFound();
        $this->postJson('/api/v1/admin/bookings/abc/reject-order', [
            'reason' => 'No booking',
        ])->assertNotFound();
    }
}
