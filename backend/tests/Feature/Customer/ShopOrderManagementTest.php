<?php

namespace Tests\Feature\Customer;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopOrderManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_customer_can_place_a_shop_order(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['shop_price' => 250, 'stock_qty' => 10]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 3]],
            'fulfillment' => 'pickup',
            'notes' => 'Please pack separately',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'status' => 'pending_confirmation',
            'is_order' => true,
            'fulfillment' => 'pickup',
            'total_amount' => '750.00',
            'estimated_total' => '0.00',
        ]);
        $code = $response->json('code');
        $this->assertMatchesRegularExpression('/^RS-\d{4}$/', $code);

        $this->assertDatabaseHas('bookings', [
            'code' => $code,
            'customer_id' => $customer->id,
            'status' => 'pending_confirmation',
            'total_amount' => 750,
        ]);
        $this->assertDatabaseHas('booking_items', [
            'service_id' => $service->id,
            'qty' => 3,
            'rate' => 250,
            'subtotal' => 750,
        ]);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 7]);

        $booking = Booking::where('code', $code)->first();
        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $service->id,
            'change_qty' => -3,
            'reason' => 'reserve',
            'booking_id' => $booking->id,
            'created_by' => $customer->id,
        ]);
        $this->assertDatabaseCount('booking_status_logs', 1);
        $this->assertDatabaseHas('booking_status_logs', [
            'status' => 'pending_confirmation',
            'changed_by' => null,
        ]);
    }

    public function test_multiple_items_sum_into_the_total(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $chicken = Service::factory()->shopSupplied()->create(['shop_price' => 300, 'stock_qty' => 10]);
        $liempo = Service::factory()->shopSupplied()->create(['shop_price' => 200, 'stock_qty' => 10]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['service_id' => $chicken->id, 'qty' => 2],
                ['service_id' => $liempo->id, 'qty' => 1],
            ],
            'fulfillment' => 'pickup',
        ]);

        $response->assertCreated();
        // 2 * 300 + 1 * 200 = 800
        $response->assertJson(['total_amount' => '800.00']);
    }

    public function test_insufficient_stock_is_rejected_and_nothing_is_written(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 2]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 3]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.qty']);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 2]);
        $this->assertDatabaseMissing('bookings', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('inventory_logs', ['service_id' => $service->id]);
    }

    public function test_insufficient_stock_on_one_item_rolls_back_the_whole_order(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $plenty = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);
        $scarce = Service::factory()->shopSupplied()->create(['stock_qty' => 1]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [
                ['service_id' => $plenty->id, 'qty' => 5],
                ['service_id' => $scarce->id, 'qty' => 2],
            ],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseHas('services', ['id' => $plenty->id, 'stock_qty' => 10]);
        $this->assertDatabaseHas('services', ['id' => $scarce->id, 'stock_qty' => 1]);
        $this->assertDatabaseMissing('bookings', ['customer_id' => $customer->id]);
    }

    public function test_a_customer_supplied_only_service_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create(['allow_customer_supplied' => true, 'allow_shop_supplied' => false]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.service_id']);
    }

    public function test_an_inactive_service_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['is_active' => false]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.service_id']);
    }

    public function test_delivery_without_an_address_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'delivery',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_delivery_with_an_address_is_accepted(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'delivery',
            'delivery_address' => '123 Rizal St, Manila',
        ]);

        $response->assertCreated();
        $response->assertJson(['delivery_address' => '123 Rizal St, Manila']);
    }

    public function test_an_over_bound_quantity_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 100000]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 5000]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.qty']);
    }

    public function test_more_than_twenty_items_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 1000]);

        $items = array_fill(0, 21, ['service_id' => $service->id, 'qty' => 1]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => $items,
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_admin_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertForbidden();
    }

    public function test_a_disabled_customer_is_forbidden(): void
    {
        $customer = User::factory()->completeProfile()->create(['is_active' => false]);
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $response = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $service->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ]);

        $response->assertUnauthorized();
    }

    public function test_codes_share_the_same_sequence_as_bookings(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $roasting = Service::factory()->create();
        $shop = Service::factory()->shopSupplied()->create(['stock_qty' => 5]);

        $first = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $roasting->id, 'est_weight_kg' => 1]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('code');

        $second = $this->postJson('/api/v1/orders', [
            'items' => [['service_id' => $shop->id, 'qty' => 1]],
            'fulfillment' => 'pickup',
        ])->assertCreated()->json('code');

        $this->assertNotSame($first, $second);
    }
}
