<?php

namespace Tests\Feature\Admin;

use App\Models\AdminPermission;
use App\Models\Booking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingPaymentTest extends TestCase
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

    private function loginAsAdminWithoutPaymentsPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    public function test_a_confirmed_bring_your_own_booking_can_be_paid_in_full(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create([
            'source_type' => 'customer_supplied',
            'status' => 'confirmed',
            'total_amount' => 705,
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", [
            'method' => 'gcash',
            'reference_no' => 'GC-123',
        ]);

        $response->assertOk();
        $this->assertEquals(705.0, (float) $response->json('paid_amount'));
        $this->assertEquals(0.0, (float) $response->json('balance'));

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'type' => 'full',
            'amount' => 705,
            'method' => 'gcash',
            'reference_no' => 'GC-123',
            'status' => 'paid',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_a_confirmed_shop_supplied_booking_can_be_paid_in_full(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create([
            'source_type' => 'shop_supplied',
            'status' => 'confirmed',
            'total_amount' => 300,
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", [
            'method' => 'cash',
        ]);

        $response->assertOk();
        $this->assertEquals(300.0, (float) $response->json('paid_amount'));
        $this->assertEquals(0.0, (float) $response->json('balance'));

        $this->assertDatabaseHas('payments', [
            'booking_id' => $booking->id,
            'type' => 'full',
            'amount' => 300,
            'method' => 'cash',
            'status' => 'paid',
        ]);
    }

    public function test_a_booking_not_yet_confirmed_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", [
            'method' => 'cash',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $this->assertDatabaseMissing('payments', ['booking_id' => $booking->id]);
    }

    public function test_a_second_payment_attempt_on_an_already_paid_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create([
            'status' => 'confirmed',
            'total_amount' => 500,
        ]);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'cash'])->assertOk();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'cash']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['amount']);
        $this->assertSame(1, $booking->payments()->where('status', 'paid')->count());
    }

    public function test_admin_without_payments_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutPaymentsPermission();
        $booking = Booking::factory()->create(['status' => 'confirmed', 'total_amount' => 500]);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'cash'])
            ->assertForbidden();
    }

    public function test_admin_with_payments_permission_can_record_a_payment(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $granter = User::factory()->superAdmin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'payments',
            'granted_by' => $granter->id,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        $booking = Booking::factory()->create(['status' => 'confirmed', 'total_amount' => 500]);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'cash'])
            ->assertOk();
    }

    public function test_customer_is_forbidden(): void
    {
        $booking = Booking::factory()->create(['status' => 'confirmed', 'total_amount' => 500]);
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'cash'])
            ->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $booking = Booking::factory()->create(['status' => 'confirmed', 'total_amount' => 500]);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'cash'])
            ->assertUnauthorized();
    }

    public function test_an_invalid_method_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'confirmed', 'total_amount' => 500]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/payments", ['method' => 'bank']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['method']);
    }
}
