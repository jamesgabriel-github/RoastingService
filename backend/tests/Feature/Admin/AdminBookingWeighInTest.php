<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBookingWeighInTest extends TestCase
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

    /**
     * @return array{0: Booking, 1: object, 2: object}
     */
    private function createApprovedBookingWithTwoItems(): array
    {
        $booking = Booking::factory()->create();
        $serviceA = Service::factory()->create();
        $serviceB = Service::factory()->create();

        $itemA = $booking->items()->create([
            'service_id' => $serviceA->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'approved',
        ]);

        $itemB = $booking->items()->create([
            'service_id' => $serviceB->id,
            'qty' => 1,
            'est_weight_kg' => 2,
            'rate' => 100,
            'subtotal' => 200,
            'status' => 'approved',
        ]);

        return [$booking, $itemA, $itemB];
    }

    public function test_weigh_in_succeeds_from_approved_and_computes_totals(): void
    {
        $admin = $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('items.0.status', 'confirmed');
        $response->assertJsonPath('items.0.confirmed_by_name', $admin->name);
        // 150 * 3.5 = 525.00, 100 * 1.8 = 180.00, total = 705.00
        $this->assertEquals(705.0, (float) $response->json('total_amount'));

        $booking->refresh();
        $itemA->refresh();
        $itemB->refresh();

        $this->assertSame('confirmed', $itemA->status);
        $this->assertSame('confirmed', $itemB->status);
        $this->assertEquals(705.0, (float) $booking->total_amount);
        $this->assertEquals(3.5, (float) $itemA->final_weight_kg);
        $this->assertEquals(525.0, (float) $itemA->subtotal);
        $this->assertEquals(1.8, (float) $itemB->final_weight_kg);
        $this->assertEquals(180.0, (float) $itemB->subtotal);
        $this->assertNotNull($itemA->weighed_at);
        $this->assertNotNull($itemA->confirmed_at);
        $this->assertSame($admin->id, $itemA->confirmed_by);
        $this->assertDatabaseHas('booking_status_logs', [
            'booking_id' => $booking->id,
            'booking_item_id' => $itemA->id,
            'status' => 'confirmed',
            'changed_by' => $admin->id,
        ]);
    }

    public function test_weigh_in_on_a_booking_not_in_approved_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create();
        $service = Service::factory()->create();
        $item = $booking->items()->create([
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'pending_review',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [['id' => $item->id, 'final_weight_kg' => 3.5]],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
    }

    public function test_weigh_in_on_a_shop_supplied_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['is_order' => true]);
        $service = Service::factory()->create();
        $item = $booking->items()->create([
            'service_id' => $service->id,
            'qty' => 3,
            'rate' => 250,
            'subtotal' => 750,
            'status' => 'pending_confirmation',
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [['id' => $item->id, 'final_weight_kg' => 1]],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['status']);
        $item->refresh();
        $this->assertSame('pending_confirmation', $item->status);
        $this->assertEquals(750.0, (float) $item->subtotal);
    }

    public function test_duplicate_item_ids_are_rejected(): void
    {
        $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
                ['id' => $itemA->id, 'final_weight_kg' => 9.9],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.id', 'items.1.id']);
    }

    public function test_an_item_id_not_on_the_booking_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();
        $otherBookingItem = Booking::factory()->create();
        $service = Service::factory()->create();
        $foreignItem = $otherBookingItem->items()->create([
            'service_id' => $service->id,
            'qty' => 1,
            'est_weight_kg' => 1,
            'rate' => 100,
            'subtotal' => 100,
        ]);

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
                ['id' => $foreignItem->id, 'final_weight_kg' => 1.8],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_omitting_a_booking_item_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items']);
    }

    public function test_a_missing_final_weight_kg_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.final_weight_kg']);
    }

    public function test_a_zero_final_weight_kg_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 0],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.final_weight_kg']);
    }

    public function test_an_out_of_range_final_weight_kg_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $response = $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 1001],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['items.0.final_weight_kg']);
    }

    public function test_admin_without_bookings_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutBookingsPermission();
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ])->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ])->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        [$booking, $itemA, $itemB] = $this->createApprovedBookingWithTwoItems();

        $this->postJson("/api/v1/admin/bookings/{$booking->id}/weigh-in", [
            'items' => [
                ['id' => $itemA->id, 'final_weight_kg' => 3.5],
                ['id' => $itemB->id, 'final_weight_kg' => 1.8],
            ],
        ])->assertUnauthorized();
    }

    public function test_a_missing_or_non_numeric_id_is_not_found(): void
    {
        $this->loginAsSuperAdmin();

        $this->postJson('/api/v1/admin/bookings/999999/weigh-in', [
            'items' => [['id' => 1, 'final_weight_kg' => 1]],
        ])->assertNotFound();

        $this->postJson('/api/v1/admin/bookings/abc/weigh-in', [
            'items' => [['id' => 1, 'final_weight_kg' => 1]],
        ])->assertNotFound();
    }
}
