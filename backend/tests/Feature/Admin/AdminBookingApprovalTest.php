<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    private function bookingWithItemStatus(string $status, array $bookingAttributes = []): Booking
    {
        $booking = Booking::factory()->create($bookingAttributes);
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => $status,
        ]);

        return $booking->load('items');
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

    public function test_approve_succeeds_from_pending_review(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_review');
        $dropoffAt = now()->addDays(2)->toIso8601String();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => $dropoffAt,
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'approved');
        $response->assertJsonPath('items.0.approved_by_name', $admin->name);
        $this->assertNotNull($response->json('dropoff_at'));
        $this->assertNotNull($response->json('items.0.approved_at'));

        $item = $booking->items->first()->fresh();
        $booking->refresh();
        $this->assertSame('approved', $item->status);
        $this->assertSame($admin->id, $item->approved_by);
        $this->assertNotNull($item->approved_at);
        $this->assertNotNull($booking->dropoff_at);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'status' => 'approved',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_approve_on_a_booking_not_in_pending_review_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('approved');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_approve_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_confirmation', ['is_order' => true]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_a_missing_dropoff_at_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_review');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['dropoff_at']);
    }

    public function test_a_past_dropoff_at_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_review');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->subDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['dropoff_at']);
    }

    public function test_reject_succeeds_and_sets_reason(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_review');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Raw food quantity too small for this service',
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'rejected');
        $response->assertJsonPath('items.0.reject_reason', 'Raw food quantity too small for this service');

        $item = $booking->items->first()->fresh();
        $this->assertSame('rejected', $item->status);
        $this->assertSame('Raw food quantity too small for this service', $item->reject_reason);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $item->id,
            'status' => 'rejected',
            'changed_by' => $admin->id,
            'remarks' => 'Raw food quantity too small for this service',
        ]);
    }

    public function test_reject_on_a_booking_not_in_pending_review_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('cooking');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Too late to reject now',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_reject_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_confirmation', ['is_order' => true]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Not a bring-your-own booking',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $item = $booking->items->first()->fresh();
        $this->assertSame('pending_confirmation', $item->status);
    }

    public function test_a_missing_reason_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->bookingWithItemStatus('pending_review');

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $booking = $this->bookingWithItemStatus('pending_review');

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'No permission',
        ])->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $booking = $this->bookingWithItemStatus('pending_review');
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'No permission',
        ])->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $booking = $this->bookingWithItemStatus('pending_review');

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertUnauthorized();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'No permission',
        ])->assertUnauthorized();
    }

    public function test_a_missing_or_non_numeric_id_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->postJson('/api/v1/admin/bookings/999999/approve', [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertNotFound();

        $this->postJson('/api/v1/admin/bookings/abc/approve', [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertNotFound();
    }
}
