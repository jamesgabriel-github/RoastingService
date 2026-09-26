<?php

namespace Tests\Feature\Customer;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_customer_can_create_a_customer_supplied_booking(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create(['roasting_rate_per_kg' => 150]);

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [
                ['service_id' => $service->id, 'est_weight_kg' => 4],
            ],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
            'notes' => 'Extra crispy please',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'status' => 'pending_review',
            'is_order' => false,
            'fulfillment' => 'pickup',
            'estimated_total' => '600.00',
        ]);
        $code = $response->json('code');
        $this->assertMatchesRegularExpression('/^RS-\d{4}$/', $code);

        $this->assertDatabaseHas('bookings', [
            'code' => $code,
            'customer_id' => $customer->id,
            'estimated_total' => 600,
            'total_amount' => null,
        ]);
        $this->assertDatabaseHas('booking_items', [
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => 4,
            'rate' => 150,
            'subtotal' => 600,
            'status' => 'pending_review',
        ]);
        $this->assertDatabaseCount('booking_status_logs', 1);
        $this->assertDatabaseHas('booking_status_logs', [
            'status' => 'pending_review',
            'changed_by' => null,
        ]);
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 0]);
    }

    public function test_multiple_items_sum_into_the_estimated_total(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $chicken = Service::factory()->create(['roasting_rate_per_kg' => 150]);
        $lechon = Service::factory()->create(['roasting_rate_per_kg' => 200]);

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [
                ['service_id' => $chicken->id, 'est_weight_kg' => 2],
                ['service_id' => $lechon->id, 'est_weight_kg' => 5],
            ],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertCreated();
        // 2 * 150 + 5 * 200 = 300 + 1000 = 1300
        $response->assertJson(['estimated_total' => '1300.00']);
        $this->assertDatabaseCount('booking_items', 2);
    }

    public function test_a_service_that_does_not_allow_customer_supplied_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->shopSupplied()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.service_id']);
    }

    public function test_an_inactive_service_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create(['is_active' => false]);

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.service_id']);
    }

    public function test_delivery_without_an_address_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'delivery',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['delivery_address']);
    }

    public function test_delivery_with_an_address_is_accepted(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'delivery',
            'delivery_address' => '123 Rizal St, Manila',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertCreated();
        $response->assertJson(['delivery_address' => '123 Rizal St, Manila']);
    }

    public function test_a_past_preferred_dropoff_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->subDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['preferred_dropoff_at']);
    }

    public function test_a_missing_preferred_pickup_at_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['preferred_pickup_at']);
    }

    public function test_a_preferred_pickup_before_dropoff_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDays(2)->toIso8601String(),
            'preferred_pickup_at' => now()->addDay()->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['preferred_pickup_at']);
    }

    public function test_an_over_bounds_weight_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 5000]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.est_weight_kg']);
    }

    public function test_notes_and_delivery_address_up_to_the_validated_bound_are_accepted(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'delivery',
            'delivery_address' => str_repeat('a', 500),
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
            'notes' => str_repeat('b', 1000),
        ]);

        $response->assertCreated();
    }

    public function test_a_weight_with_more_than_two_decimals_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2.555]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.est_weight_kg']);
    }

    public function test_more_than_twenty_items_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $items = array_fill(0, 21, ['service_id' => $service->id, 'est_weight_kg' => 1]);

        $response = $this->postJson('/api/v1/bookings', [
            'items' => $items,
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_a_disabled_customer_is_forbidden(): void
    {
        $customer = User::factory()->completeProfile()->create(['is_active' => false]);
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertForbidden();
    }

    public function test_an_empty_items_list_is_rejected(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_admin_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertForbidden();
    }

    public function test_super_admin_is_forbidden(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $this->actingAs($superAdmin, 'web');
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $service = Service::factory()->create();

        $response = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 2]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertUnauthorized();
    }

    public function test_codes_increment_across_bookings(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');
        $service = Service::factory()->create();

        $first = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 1]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->json('code');

        $second = $this->postJson('/api/v1/bookings', [
            'items' => [['service_id' => $service->id, 'est_weight_kg' => 1]],
            'fulfillment' => 'pickup',
            'preferred_dropoff_at' => now()->addDay()->toIso8601String(),
            'preferred_pickup_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->json('code');

        $this->assertNotSame($first, $second);
    }
}
