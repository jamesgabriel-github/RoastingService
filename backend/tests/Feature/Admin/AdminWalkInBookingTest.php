<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminWalkInBookingTest extends TestCase
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

    private function customer(array $attributes = []): User
    {
        return User::factory()->completeProfile()->create($attributes);
    }

    public function test_roasting_walk_in_for_a_registered_customer_succeeds(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $customer = $this->customer();
        $service = Service::factory()->create(['roasting_rate_per_kg' => 150]);

        $response = $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'customer_id' => $customer->id,
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 2.5]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'confirmed');
        $response->assertJsonPath('source_type', 'customer_supplied');
        $response->assertJsonPath('total_amount', '375.00');
        $this->assertNotNull($response->json('approved_at'));
        $this->assertNotNull($response->json('weighed_at'));
        $this->assertNotNull($response->json('confirmed_at'));
        $this->assertNotNull($response->json('dropoff_at'));

        $booking = Booking::first();
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertNull($booking->guest_name);
        $this->assertNull($booking->guest_phone);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame('375.00', $booking->total_amount);

        $this->assertDatabaseHas('booking_status_logs', ['booking_id' => $booking->id, 'status' => 'pending_review']);
        $this->assertDatabaseHas('booking_status_logs', ['booking_id' => $booking->id, 'status' => 'approved', 'changed_by' => $admin->id]);
        $this->assertDatabaseHas('booking_status_logs', ['booking_id' => $booking->id, 'status' => 'confirmed', 'changed_by' => $admin->id]);
        $this->assertSame(3, $booking->statusLogs()->count());
    }

    public function test_roasting_walk_in_for_a_guest_succeeds(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create(['roasting_rate_per_kg' => 150]);

        $response = $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 2]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertCreated();

        $booking = Booking::first();
        $this->assertNull($booking->customer_id);
        $this->assertSame('Jane Dela Cruz', $booking->guest_name);
        $this->assertSame('09171234567', $booking->guest_phone);
    }

    public function test_shop_walk_in_for_a_registered_customer_succeeds(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $customer = $this->customer();
        $serviceA = Service::factory()->shopSupplied()->create(['stock_qty' => 10, 'shop_price' => 100]);
        $serviceB = Service::factory()->shopSupplied()->create(['stock_qty' => 5, 'shop_price' => 50]);

        $response = $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'customer_id' => $customer->id,
            'items' => [
                ['service_id' => $serviceA->id, 'qty' => 2],
                ['service_id' => $serviceB->id, 'qty' => 1],
            ],
            'fulfillment' => 'pickup',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('status', 'confirmed');
        $response->assertJsonPath('source_type', 'shop_supplied');
        $response->assertJsonPath('total_amount', '250.00');

        $booking = Booking::first();
        $this->assertSame($customer->id, $booking->customer_id);
        $this->assertSame($admin->id, $booking->confirmed_by);
        $this->assertNotNull($booking->confirmed_at);

        $this->assertSame(8, $serviceA->fresh()->stock_qty);
        $this->assertSame(4, $serviceB->fresh()->stock_qty);

        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $serviceA->id,
            'booking_id' => $booking->id,
            'reason' => 'reserve',
            'change_qty' => -2,
            'created_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $serviceB->id,
            'booking_id' => $booking->id,
            'reason' => 'reserve',
            'change_qty' => -1,
            'created_by' => $admin->id,
        ]);

        $this->assertDatabaseHas('booking_status_logs', ['booking_id' => $booking->id, 'status' => 'pending_confirmation']);
        $this->assertDatabaseHas('booking_status_logs', ['booking_id' => $booking->id, 'status' => 'confirmed', 'changed_by' => $admin->id]);
        $this->assertSame(2, $booking->statusLogs()->count());
    }

    public function test_shop_walk_in_for_a_guest_succeeds(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 10, 'shop_price' => 100]);

        $response = $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Mark Santos',
            'guest_phone' => '09179998888',
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertCreated();

        $booking = Booking::first();
        $this->assertNull($booking->customer_id);
        $this->assertSame('Mark Santos', $booking->guest_name);
        $this->assertSame('09179998888', $booking->guest_phone);
    }

    public function test_shop_walk_in_with_insufficient_stock_rolls_back(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 1, 'shop_price' => 100]);

        $response = $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Mark Santos',
            'guest_phone' => '09179998888',
            'items' => [['service_id' => $service->id, 'qty' => 5]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.qty']);

        $this->assertSame(0, Booking::count());
        $this->assertSame(1, $service->fresh()->stock_qty);
    }

    public function test_neither_customer_nor_guest_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
    }

    public function test_both_customer_and_guest_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $customer = $this->customer();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'customer_id' => $customer->id,
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
    }

    public function test_guest_name_without_guest_phone_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['guest_phone']);
    }

    public function test_guest_phone_without_guest_name_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['guest_name']);
    }

    public function test_unknown_customer_id_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'customer_id' => 999999,
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
    }

    public function test_non_customer_customer_id_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'customer_id' => $admin->id,
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['customer_id']);
    }

    public function test_a_service_that_does_not_allow_the_booking_type_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $shopOnly = Service::factory()->shopSupplied()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $shopOnly->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.service_id']);

        $roastingOnly = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $roastingOnly->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.service_id']);
    }

    public function test_an_inactive_service_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $inactive = Service::factory()->create(['is_active' => false]);

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $inactive->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.service_id']);
    }

    public function test_delivery_without_delivery_address_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'delivery',
        ])->assertUnprocessable()->assertJsonValidationErrors(['delivery_address']);

        $shopService = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $shopService->id, 'qty' => 1]],
            'fulfillment' => 'delivery',
        ])->assertUnprocessable()->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_search_customers_matches_by_phone_and_name(): void
    {
        $this->loginAsSuperAdmin();
        $byPhone = $this->customer(['phone' => '09171234567', 'first_name' => 'Alice', 'last_name' => 'Reyes']);
        $byName = $this->customer(['phone' => '09280001111', 'first_name' => 'Bob', 'last_name' => 'Santos']);
        $this->customer(['phone' => '09999999999', 'first_name' => 'Carl', 'last_name' => 'Cruz']);

        $phoneResponse = $this->getJson('/api/v1/admin/bookings/customers?search=917123');
        $phoneResponse->assertOk();
        $phoneResponse->assertJsonFragment(['id' => $byPhone->id, 'name' => 'Alice Reyes']);

        $nameResponse = $this->getJson('/api/v1/admin/bookings/customers?search=Santos');
        $nameResponse->assertOk();
        $nameResponse->assertJsonFragment(['id' => $byName->id, 'name' => 'Bob Santos']);
    }

    public function test_search_customers_excludes_non_customer_roles(): void
    {
        $this->loginAsSuperAdmin();
        User::factory()->admin()->create(['first_name' => 'Zara', 'last_name' => 'Adminson', 'phone' => '09171112222']);
        User::factory()->superAdmin()->create(['first_name' => 'Zara', 'last_name' => 'Superson', 'phone' => '09171113333']);

        $response = $this->getJson('/api/v1/admin/bookings/customers?search=Zara');

        $response->assertOk();
        $this->assertCount(0, $response->json());
    }

    public function test_search_customers_respects_the_ten_row_limit(): void
    {
        $this->loginAsSuperAdmin();
        User::factory()->count(12)->create(['first_name' => 'Search', 'phone' => fn () => '0917'.fake()->unique()->numerify('#######')]);

        $response = $this->getJson('/api/v1/admin/bookings/customers?search=Search');

        $response->assertOk();
        $this->assertCount(10, $response->json());
    }

    public function test_search_customers_requires_at_least_two_characters(): void
    {
        $this->loginAsSuperAdmin();

        $this->getJson('/api/v1/admin/bookings/customers')->assertUnprocessable()->assertJsonValidationErrors(['search']);
        $this->getJson('/api/v1/admin/bookings/customers?search=a')->assertUnprocessable()->assertJsonValidationErrors(['search']);
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        $service = Service::factory()->create();
        $shopService = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $this->getJson('/api/v1/admin/bookings/customers?search=ab')->assertForbidden();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertForbidden();

        $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $shopService->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ])->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $customer = $this->customer();
        $this->actingAs($customer);
        $service = Service::factory()->create();
        $shopService = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $this->getJson('/api/v1/admin/bookings/customers?search=ab')->assertForbidden();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertForbidden();

        $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $shopService->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ])->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $service = Service::factory()->create();
        $shopService = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $this->getJson('/api/v1/admin/bookings/customers?search=ab')->assertUnauthorized();

        $this->postJson('/api/v1/admin/bookings/walk-in-roasting', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $service->id, 'final_weight_kg' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnauthorized();

        $this->postJson('/api/v1/admin/bookings/walk-in-shop', [
            'guest_name' => 'Jane Dela Cruz',
            'guest_phone' => '09171234567',
            'items' => [['service_id' => $shopService->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ])->assertUnauthorized();
    }
}
