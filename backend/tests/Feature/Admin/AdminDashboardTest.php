<?php

namespace Tests\Feature\Admin;

use App\Models\AdminPermission;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
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

    private function loginAsAdminWithoutDashboardPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    public function test_dashboard_returns_sales_status_queue_top_items_and_low_stock(): void
    {
        // Wednesday, mid-month: leaves room for a "this week but not today" and
        // a "this month but not this week" bucket regardless of week-start config.
        $this->travelTo(Carbon::create(2026, 6, 17, 12, 0, 0));
        $this->loginAsSuperAdmin();

        $weekStart = now()->startOfWeek();
        $monthStart = now()->startOfMonth();

        $serviceA = Service::factory()->create(['name' => 'Whole Chicken']);
        $serviceB = Service::factory()->create(['name' => 'Pork Belly']);
        $lowStockService = Service::factory()->shopSupplied()->create([
            'name' => 'Lechon Belly',
            'stock_qty' => 2,
            'low_stock_threshold' => 5,
        ]);
        Service::factory()->shopSupplied()->create([
            'name' => 'Healthy Stock',
            'stock_qty' => 20,
            'low_stock_threshold' => 5,
        ]);
        Service::factory()->shopSupplied()->create([
            'name' => 'Inactive Low Stock',
            'stock_qty' => 1,
            'low_stock_threshold' => 5,
            'is_active' => false,
        ]);

        $todayBooking = Booking::factory()->create(['is_order' => false]);
        $todayBooking->items()->create([
            'service_id' => $serviceA->id,
            'qty' => 5,
            'est_weight_kg' => 3,
            'rate' => 150,
            'subtotal' => 450,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'booking_id' => $todayBooking->id,
            'amount' => 100,
            'status' => 'paid',
            'paid_at' => now()->subHours(2),
        ]);

        $weekBooking = Booking::factory()->create(['is_order' => true]);
        $weekBooking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 150,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'booking_id' => $weekBooking->id,
            'amount' => 50,
            'status' => 'paid',
            'paid_at' => $weekStart->copy()->addHours(3),
        ]);

        $monthBooking = Booking::factory()->create(['is_order' => false]);
        $monthBooking->items()->create([
            'service_id' => $serviceB->id,
            'qty' => 3,
            'est_weight_kg' => 2,
            'rate' => 150,
            'subtotal' => 300,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'booking_id' => $monthBooking->id,
            'amount' => 25,
            'status' => 'paid',
            'paid_at' => $monthStart->copy()->addHours(3),
        ]);

        $olderBooking = Booking::factory()->create(['is_order' => true]);
        $olderBooking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 150,
            'status' => 'completed',
        ]);
        Payment::factory()->create([
            'booking_id' => $olderBooking->id,
            'amount' => 999,
            'status' => 'paid',
            'paid_at' => $monthStart->copy()->subDays(5),
        ]);

        collect(range(1, 2))->each(function () {
            $booking = Booking::factory()->create();
            $booking->items()->create([
                'service_id' => Service::factory()->create()->id,
                'qty' => 1,
                'rate' => 150,
                'subtotal' => 150,
                'status' => 'pending_review',
            ]);
        });

        $cookingBooking = Booking::factory()->create(['created_at' => now()->subMinutes(12)]);
        $cookingBooking->items()->create([
            'service_id' => $serviceA->id,
            'qty' => 100,
            'est_weight_kg' => 1,
            'rate' => 150,
            'subtotal' => 150,
            'status' => 'cooking',
            'cooking_started_at' => now()->subMinutes(12),
            'est_ready_at' => now()->addMinutes(30),
        ]);

        $readyBooking = Booking::factory()->create();
        $readyBooking->items()->create([
            'service_id' => Service::factory()->create()->id,
            'qty' => 1,
            'rate' => 150,
            'subtotal' => 150,
            'status' => 'ready',
        ]);

        $response = $this->getJson('/api/v1/admin/dashboard');

        $response->assertOk();
        $response->assertJson([
            'sales' => [
                'today' => ['not_order' => '100.00', 'is_order' => '0.00', 'total' => '100.00'],
                'week' => ['not_order' => '100.00', 'is_order' => '50.00', 'total' => '150.00'],
                'month' => ['not_order' => '125.00', 'is_order' => '50.00', 'total' => '175.00'],
            ],
            'bookings_by_status' => [
                'pending_review' => 2,
                'pending_confirmation' => 0,
                'approved' => 0,
                'confirmed' => 0,
                'cooking' => 1,
                'ready' => 1,
                'out_for_delivery' => 0,
                'completed' => 4,
                'rejected' => 0,
                'no_show' => 0,
                'cancelled' => 0,
            ],
        ]);

        $queueCodes = collect($response->json('active_queue'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing([$cookingBooking->code, $readyBooking->code], $queueCodes);

        $cookingEntry = collect($response->json('active_queue'))->firstWhere('code', $cookingBooking->code);
        $this->assertSame(12, $cookingEntry['waiting_minutes']);

        $response->assertJson([
            'top_items' => [
                ['service_id' => $serviceA->id, 'name' => 'Whole Chicken', 'qty_sold' => 5],
                ['service_id' => $serviceB->id, 'name' => 'Pork Belly', 'qty_sold' => 3],
            ],
        ]);

        $lowStock = collect($response->json('low_stock'));
        $this->assertCount(1, $lowStock);
        $this->assertSame($lowStockService->id, $lowStock->first()['id']);
        $this->assertSame(2, $lowStock->first()['stock_qty']);
    }

    public function test_admin_without_dashboard_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutDashboardPermission();

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_admin_with_dashboard_permission_can_view(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $granter = User::factory()->superAdmin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'dashboard',
            'granted_by' => $granter->id,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        $this->getJson('/api/v1/admin/dashboard')->assertOk();
    }

    public function test_customer_is_forbidden(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->getJson('/api/v1/admin/dashboard')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/dashboard')->assertUnauthorized();
    }
}
