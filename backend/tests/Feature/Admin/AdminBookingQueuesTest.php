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

    /**
     * Create a booking with a single item at `$status`.
     */
    private function itemWithStatus(string $status, array $bookingAttributes = [], array $itemAttributes = [])
    {
        $booking = Booking::factory()->create($bookingAttributes);

        return $booking->items()->create(array_merge([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => $status,
        ], $itemAttributes));
    }

    public function test_counts_reflect_seeded_bookings_per_group(): void
    {
        $this->loginAsSuperAdmin();
        $this->itemWithStatus('pending_review');
        $this->itemWithStatus('pending_review');
        $this->itemWithStatus('cooking');

        $response = $this->getJson('/api/v1/admin/bookings/counts');

        $response->assertOk();
        $response->assertJson([
            'draft' => 2,
            'pending' => 0,
            'cooking' => 1,
            'ready' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ]);
    }

    public function test_group_filter_returns_only_that_queue(): void
    {
        $this->loginAsSuperAdmin();
        $pending = $this->itemWithStatus('pending_review');
        $this->itemWithStatus('cooking');

        $response = $this->getJson('/api/v1/admin/bookings?group=draft');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$pending->booking->code], $codes);
    }

    public function test_a_group_spans_its_raw_statuses(): void
    {
        $this->loginAsSuperAdmin();
        $ready = $this->itemWithStatus('ready');
        $outForDelivery = $this->itemWithStatus('out_for_delivery');
        $this->itemWithStatus('cooking');

        $response = $this->getJson('/api/v1/admin/bookings?group=ready');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing([$ready->booking->code, $outForDelivery->booking->code], $codes);
    }

    public function test_date_filter_excludes_bookings_outside_the_selected_date(): void
    {
        $this->loginAsSuperAdmin();
        $today = $this->itemWithStatus('pending_review', ['preferred_pickup_at' => now()]);
        $this->itemWithStatus('pending_review', ['preferred_pickup_at' => now()->addDays(3)]);

        $response = $this->getJson('/api/v1/admin/bookings?group=draft&date='.now()->toDateString());

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$today->booking->code], $codes);
    }

    public function test_an_invalid_group_is_rejected(): void
    {
        $this->loginAsSuperAdmin();

        $this->getJson('/api/v1/admin/bookings?group=completed_orders')->assertUnprocessable();
    }

    public function test_waiting_minutes_is_a_non_negative_whole_number(): void
    {
        $this->loginAsSuperAdmin();
        $item = $this->itemWithStatus('pending_review');
        $log = BookingStatusLog::create(['booking_id' => $item->booking_id, 'booking_item_id' => $item->id, 'status' => 'pending_review']);
        $log->created_at = now()->subMinutes(15);
        $log->save();

        $response = $this->getJson('/api/v1/admin/bookings?group=draft');

        $response->assertOk();
        $waitingMinutes = $response->json('data.0.waiting_minutes');
        $this->assertIsInt($waitingMinutes);
        $this->assertGreaterThanOrEqual(15, $waitingMinutes);
        $this->assertLessThan(16, $waitingMinutes);
    }

    public function test_search_matches_by_code(): void
    {
        $this->loginAsSuperAdmin();
        $booking = $this->itemWithStatus('pending_review', ['code' => 'RS-4242'])->booking;
        $this->itemWithStatus('pending_review', ['code' => 'RS-9999']);

        $response = $this->getJson('/api/v1/admin/bookings?group=draft&search=4242');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$booking->code], $codes);
    }

    public function test_search_matches_by_registered_customer_name(): void
    {
        $this->loginAsSuperAdmin();
        $customer = User::factory()->create(['first_name' => 'Juan', 'last_name' => 'Dela Cruz']);
        $booking = $this->itemWithStatus('pending_review', ['customer_id' => $customer->id])->booking;
        $this->itemWithStatus('pending_review');

        $response = $this->getJson('/api/v1/admin/bookings?'.http_build_query([
            'group' => 'draft',
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
        $booking = $this->itemWithStatus('pending_review', ['customer_id' => $customer->id])->booking;
        $this->itemWithStatus('pending_review');

        $response = $this->getJson('/api/v1/admin/bookings?group=draft&search=09171234567');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$booking->code], $codes);
    }

    public function test_results_are_ordered_by_longest_waiting_first(): void
    {
        $this->loginAsSuperAdmin();
        $older = $this->itemWithStatus('pending_review');
        $olderLog = BookingStatusLog::create(['booking_id' => $older->booking_id, 'booking_item_id' => $older->id, 'status' => 'pending_review']);
        $olderLog->created_at = now()->subHours(2);
        $olderLog->save();

        $newer = $this->itemWithStatus('pending_review');
        $newerLog = BookingStatusLog::create(['booking_id' => $newer->booking_id, 'booking_item_id' => $newer->id, 'status' => 'pending_review']);
        $newerLog->created_at = now()->subMinutes(5);
        $newerLog->save();

        $response = $this->getJson('/api/v1/admin/bookings?group=draft');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$older->booking->code, $newer->booking->code], $codes);
    }

    public function test_a_row_represents_one_item_not_one_booking(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create();
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();

        $booking->items()->create([
            'service_id' => $serviceA->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'pending_review',
        ]);
        $booking->items()->create([
            'service_id' => $serviceB->id,
            'qty' => 1,
            'est_weight_kg' => 2,
            'rate' => 100,
            'subtotal' => 200,
            'status' => 'pending_review',
        ]);

        $response = $this->getJson('/api/v1/admin/bookings?group=draft');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        $this->assertSame([$booking->code, $booking->code], $codes);
    }

    public function test_admin_with_bookings_permission_can_view_queues(): void
    {
        $this->loginAsAdminWithBookingsPermission();
        $this->itemWithStatus('pending_review');

        $this->getJson('/api/v1/admin/bookings/counts')->assertOk();
        $this->getJson('/api/v1/admin/bookings?group=draft')->assertOk();
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $item = $this->itemWithStatus('pending_review');

        $this->getJson('/api/v1/admin/bookings/counts')->assertForbidden();
        $this->getJson('/api/v1/admin/bookings?group=draft')->assertForbidden();
        $this->getJson("/api/v1/admin/bookings/{$item->booking_id}")->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $item = $this->itemWithStatus('pending_review');
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->getJson("/api/v1/admin/bookings/{$item->booking_id}")->assertForbidden();

        $this->getJson('/api/v1/admin/bookings/counts')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $item = $this->itemWithStatus('pending_review');

        $this->getJson('/api/v1/admin/bookings/counts')->assertUnauthorized();
        $this->getJson("/api/v1/admin/bookings/{$item->booking_id}")->assertUnauthorized();
    }

    public function test_detail_returns_items_and_the_full_timeline(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();
        $booking = Booking::factory()->create();
        $item = $booking->items()->create([
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'approved',
        ]);
        BookingStatusLog::create(['booking_id' => $booking->id, 'booking_item_id' => $item->id, 'status' => 'pending_review']);
        BookingStatusLog::create(['booking_id' => $booking->id, 'booking_item_id' => $item->id, 'status' => 'approved']);

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
