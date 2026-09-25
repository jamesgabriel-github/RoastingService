<?php

namespace Tests\Feature\Admin;

use App\Models\AdminPermission;
use App\Models\Booking;
use App\Models\BookingStatusLog;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingQueuesTest extends TestCase
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

    private function loginAsAdminWithBookingsPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $granter = User::factory()->superAdmin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'bookings',
            'granted_by' => $granter->id,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
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

    public function test_counts_reflect_seeded_bookings_per_status(): void
    {
        $this->loginAsSuperAdmin();
        Booking::factory()->count(2)->create(['status' => 'pending_review']);
        Booking::factory()->create(['status' => 'cooking']);

        $response = $this->getJson('/api/v1/admin/bookings/counts');

        $response->assertOk();
        $response->assertJson([
            'pending_review' => 2,
            'pending_confirmation' => 0,
            'approved' => 0,
            'confirmed' => 0,
            'cooking' => 1,
            'ready' => 0,
            'out_for_delivery' => 0,
        ]);
    }

    public function test_status_filter_returns_only_that_queue(): void
    {
        $this->loginAsSuperAdmin();
        $pending = Booking::factory()->create(['status' => 'pending_review']);
        Booking::factory()->create(['status' => 'cooking']);

        $response = $this->getJson('/api/v1/admin/bookings?status=pending_review');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$pending->code], $codes);
    }

    public function test_an_invalid_status_is_rejected(): void
    {
        $this->loginAsSuperAdmin();

        $this->getJson('/api/v1/admin/bookings?status=completed')->assertUnprocessable();
    }

    public function test_waiting_minutes_is_a_non_negative_whole_number(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review']);
        $log = BookingStatusLog::create(['booking_id' => $booking->id, 'status' => 'pending_review']);
        $log->created_at = now()->subMinutes(15);
        $log->save();

        $response = $this->getJson('/api/v1/admin/bookings?status=pending_review');

        $response->assertOk();
        $waitingMinutes = $response->json('data.0.waiting_minutes');
        $this->assertIsInt($waitingMinutes);
        $this->assertGreaterThanOrEqual(15, $waitingMinutes);
        $this->assertLessThan(16, $waitingMinutes);
    }

    public function test_search_matches_by_code(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['status' => 'pending_review', 'code' => 'RS-4242']);
        Booking::factory()->create(['status' => 'pending_review', 'code' => 'RS-9999']);

        $response = $this->getJson('/api/v1/admin/bookings?status=pending_review&search=4242');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$booking->code], $codes);
    }

    public function test_search_matches_by_registered_customer_name(): void
    {
        $this->loginAsSuperAdmin();
        $customer = User::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $booking = Booking::factory()->create(['status' => 'pending_review', 'customer_id' => $customer->id]);
        Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->getJson('/api/v1/admin/bookings?'.http_build_query([
            'status' => 'pending_review',
            'search' => 'Dela Cruz',
        ]));

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$booking->code], $codes);
    }

    public function test_search_matches_by_phone(): void
    {
        $this->loginAsSuperAdmin();
        $customer = User::factory()->create(['phone' => '09171234567']);
        $booking = Booking::factory()->create(['status' => 'pending_review', 'customer_id' => $customer->id]);
        Booking::factory()->create(['status' => 'pending_review']);

        $response = $this->getJson('/api/v1/admin/bookings?status=pending_review&search=09171234567');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$booking->code], $codes);
    }

    public function test_results_are_ordered_by_longest_waiting_first(): void
    {
        $this->loginAsSuperAdmin();
        $older = Booking::factory()->create(['status' => 'pending_review']);
        $olderLog = BookingStatusLog::create(['booking_id' => $older->id, 'status' => 'pending_review']);
        $olderLog->created_at = now()->subHours(2);
        $olderLog->save();

        $newer = Booking::factory()->create(['status' => 'pending_review']);
        $newerLog = BookingStatusLog::create(['booking_id' => $newer->id, 'status' => 'pending_review']);
        $newerLog->created_at = now()->subMinutes(5);
        $newerLog->save();

        $response = $this->getJson('/api/v1/admin/bookings?status=pending_review');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$older->code, $newer->code], $codes);
    }

    public function test_admin_with_bookings_permission_can_view_queues(): void
    {
        $this->loginAsAdminWithBookingsPermission();
        Booking::factory()->create(['status' => 'pending_review']);

        $this->getJson('/api/v1/admin/bookings/counts')->assertOk();
        $this->getJson('/api/v1/admin/bookings?status=pending_review')->assertOk();
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $this->getJson('/api/v1/admin/bookings/counts')->assertForbidden();
        $this->getJson('/api/v1/admin/bookings?status=pending_review')->assertForbidden();
        $this->getJson("/api/v1/admin/bookings/{$booking->id}")->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $booking = Booking::factory()->create(['status' => 'pending_review']);
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->getJson("/api/v1/admin/bookings/{$booking->id}")->assertForbidden();

        $this->getJson('/api/v1/admin/bookings/counts')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $booking = Booking::factory()->create(['status' => 'pending_review']);

        $this->getJson('/api/v1/admin/bookings/counts')->assertUnauthorized();
        $this->getJson("/api/v1/admin/bookings/{$booking->id}")->assertUnauthorized();
    }

    public function test_detail_returns_items_and_the_full_timeline(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();
        $booking = Booking::factory()->create(['status' => 'approved']);
        $booking->items()->create([
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
        ]);
        BookingStatusLog::create(['booking_id' => $booking->id, 'status' => 'pending_review']);
        BookingStatusLog::create(['booking_id' => $booking->id, 'status' => 'approved']);

        $response = $this->getJson("/api/v1/admin/bookings/{$booking->id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('items'));
        $this->assertCount(2, $response->json('status_logs'));
    }

    public function test_a_missing_booking_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->getJson('/api/v1/admin/bookings/999999')->assertNotFound();
    }

    public function test_a_non_numeric_id_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->getJson('/api/v1/admin/bookings/abc')->assertNotFound();
    }
}
