<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
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
        $booking = Booking::factory()->create(['status' => 'pending_review']);
        $dropoffAt = now()->addDays(2)->toIso8601String();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => $dropoffAt,
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'approved');
        $response->assertJsonPath('approved_by_name', $admin->name);
        $this->assertNotNull($response->json('dropoff_at'));
        $this->assertNotNull($response->json('approved_at'));

        $booking->refresh();
        $this->assertSame('approved', $booking->status);
        $this->assertSame($admin->id, $booking->approved_by);
        $this->assertNotNull($booking->approved_at);
        $this->assertNotNull($booking->dropoff_at);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'approved',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_approve_on_a_booking_not_in_pending_review_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'approved']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_approve_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create([
            'is_order' => true,
            'status' => 'pending_confirmation',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_a_missing_dropoff_at_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['dropoff_at']);
    }

    public function test_a_past_dropoff_at_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->subDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['dropoff_at']);
    }

    public function test_reject_succeeds_and_sets_reason(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Raw food quantity too small for this service',
        ]);

        $response->assertOk();
        $response->assertJsonPath('status', 'rejected');
        $response->assertJsonPath('reject_reason', 'Raw food quantity too small for this service');

        $booking->refresh();
        $this->assertSame('rejected', $booking->status);
        $this->assertSame('Raw food quantity too small for this service', $booking->reject_reason);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'status' => 'rejected',
            'changed_by' => $admin->id,
            'remarks' => 'Raw food quantity too small for this service',
        ]);
    }

    public function test_reject_on_a_booking_not_in_pending_review_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'cooking']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Too late to reject now',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_reject_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create([
            'is_order' => true,
            'status' => 'pending_confirmation',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'Not a bring-your-own booking',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $booking->refresh();
        $this->assertSame('pending_confirmation', $booking->status);
    }

    public function test_a_missing_reason_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['reason']);
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/approve", [
            'dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertForbidden();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/reject", [
            'reason' => 'No permission',
        ])->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $booking = Booking::factory()->create(['status' => 'pending_review']);
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
        $booking = Booking::factory()->create(['status' => 'pending_review']);

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
