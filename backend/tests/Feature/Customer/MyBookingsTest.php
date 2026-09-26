<?php

namespace Tests\Feature\Customer;

use App\Models\Booking;
use App\Models\InventoryLog;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyBookingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    /**
     * Sanctum's guard caches the resolved user for its own lifetime, and that
     * guard instance persists across simulated requests within one test
     * method, so switching actors mid-test needs a fresh guard resolution.
     */
    private function actingAsCustomer(User $user): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web');
    }

    private function createBookingFor(User $customer): string
    {
        $this->actingAsCustomer($customer);
        $service = Service::factory()->create();

        $code = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->json('code');

        return $code;
    }

    public function test_a_customer_only_sees_their_own_bookings_ordered_newest_first(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $other = User::factory()->completeProfile()->create();

        $this->createBookingFor($other);
        $first = $this->createBookingFor($customer);
        $second = $this->createBookingFor($customer);

        $response = $this->getJson('/api/v1/bookings');

        $response->assertOk();
        $codes = collect($response->json())->pluck('code')->all();
        $this->assertSame([$second, $first], $codes);
    }

    public function test_requesting_another_customers_booking_is_not_found(): void
    {
        $owner = User::factory()->completeProfile()->create();
        $this->createBookingFor($owner);
        $booking = Booking::first();

        $intruder = User::factory()->completeProfile()->create();
        $this->actingAsCustomer($intruder);

        $this->getJson("/api/v1/bookings/{$booking->id}")->assertNotFound();
        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertNotFound();
    }

    public function test_a_missing_booking_is_not_found(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAsCustomer($customer);

        $this->getJson('/api/v1/bookings/999999')->assertNotFound();
    }

    public function test_a_non_numeric_id_is_not_found(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAsCustomer($customer);

        $this->getJson('/api/v1/bookings/abc')->assertNotFound();
    }

    public function test_an_id_beyond_php_int_range_is_not_found(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAsCustomer($customer);

        $this->getJson('/api/v1/bookings/99999999999999999999')->assertNotFound();
    }

    public function test_detail_includes_a_one_entry_timeline_for_a_fresh_booking(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->createBookingFor($customer);
        $booking = Booking::first();

        $response = $this->getJson("/api/v1/bookings/{$booking->id}");

        $response->assertOk();
        $logs = $response->json('status_logs');
        $this->assertCount(1, $logs);
        $this->assertSame('pending_review', $logs[0]['status']);
        $this->assertNull($logs[0]['changed_by_name']);
    }

    public function test_cancelling_a_pending_review_booking_succeeds(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->createBookingFor($customer);
        $booking = Booking::first();

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $response->assertJson(['status' => 'cancelled']);
        $this->assertDatabaseHas('booking_items', ['booking_id' => $booking->id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('booking_status_logs', ['booking_id' => $booking->id, 'status' => 'cancelled']);

        $logs = $response->json('status_logs');
        $this->assertCount(2, $logs);
        $this->assertSame('cancelled', $logs[1]['status']);
    }

    public function test_cancelling_with_the_same_service_on_two_lines_restocks_both(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAsCustomer($customer);
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);

        $this->postJson('/api/v1/orders', [
            'items' => [
                ['service_id' => $service->id, 'qty' => 2],
                ['service_id' => $service->id, 'qty' => 3],
            ],
            'fulfillment' => 'pickup',
            'preferred_pickup_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated();

        $booking = Booking::first();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 5]);

        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertOk();

        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 10]);
        $this->assertEquals(2, InventoryLog::where('booking_id', $booking->id)->where('reason', 'release')->count());
    }

    public function test_cancelling_a_shop_order_releases_stock(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAsCustomer($customer);
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);

        $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 4]],
            'fulfillment' => 'pickup',
            'preferred_pickup_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated();

        $booking = Booking::first();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 6]);

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertOk();
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 10]);
        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $service->id,
            'change_qty' => 4,
            'reason' => 'release',
            'booking_id' => $booking->id,
            'created_by' => $customer->id,
        ]);
    }

    public function test_cancelling_once_cooking_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);
        $booking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'est_weight_kg' => 2,
            'rate' => 150,
            'subtotal' => 300,
            'status' => 'cooking',
        ]);
        $this->actingAsCustomer($customer);

        $response = $this->postJson("/api/v1/bookings/{$booking->id}/cancel");

        $response->assertUnprocessable();
        $this->assertDatabaseHas('booking_items', ['booking_id' => $booking->id, 'status' => 'cooking']);
        $this->assertDatabaseCount('booking_status_logs', 0);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->createBookingFor($customer);
        $booking = Booking::first();
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/bookings')->assertUnauthorized();
        $this->getJson("/api/v1/bookings/{$booking->id}")->assertUnauthorized();
        $this->postJson("/api/v1/bookings/{$booking->id}/cancel")->assertUnauthorized();
    }

    public function test_admin_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');

        $this->getJson('/api/v1/bookings')->assertForbidden();
    }
}
