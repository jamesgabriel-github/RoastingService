<?php

namespace Tests\Feature\Admin;

use App\Models\AdminPermission;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryManagementTest extends TestCase
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

    private function loginAsAdminWithInventoryPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $granter = User::factory()->superAdmin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'inventory',
            'granted_by' => $granter->id,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    private function loginAsAdminWithoutInventoryPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    public function test_super_admin_can_restock_a_shop_supplied_service(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);

        $response = $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", [
            'qty' => 5,
            'remarks' => 'Delivery from supplier',
        ]);

        $response->assertOk();
        $response->assertJson(['stock_qty' => 15]);
        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $service->id,
            'change_qty' => 5,
            'reason' => 'restock',
            'remarks' => 'Delivery from supplier',
        ]);
    }

    public function test_admin_with_inventory_permission_can_restock(): void
    {
        $this->loginAsAdminWithInventoryPermission();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 0]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", ['qty' => 20])
            ->assertOk()
            ->assertJson(['stock_qty' => 20]);
    }

    public function test_admin_without_inventory_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutInventoryPermission();
        $service = Service::factory()->shopSupplied()->create();

        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
        $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", ['qty' => 1])->assertForbidden();
        $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => 1])->assertForbidden();
        $this->getJson('/api/v1/admin/inventory/logs')->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->getJson('/api/v1/admin/inventory')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/inventory')->assertUnauthorized();
    }

    public function test_adjust_can_increase_or_decrease_stock(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => 3])
            ->assertOk()
            ->assertJson(['stock_qty' => 13]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => -5])
            ->assertOk()
            ->assertJson(['stock_qty' => 8]);

        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $service->id,
            'change_qty' => 3,
            'reason' => 'adjust',
        ]);
        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $service->id,
            'change_qty' => -5,
            'reason' => 'adjust',
        ]);
    }

    public function test_adjust_below_zero_is_rejected_and_not_logged(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 3]);

        $response = $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => -10]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('change_qty');
        $this->assertDatabaseHas('services', ['id' => $service->id, 'stock_qty' => 3]);
        $this->assertDatabaseMissing('inventory_logs', ['service_id' => $service->id]);
    }

    public function test_restock_on_a_non_shop_supplied_service_is_not_found(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->create(['allow_customer_supplied' => true, 'allow_shop_supplied' => false]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", ['qty' => 5])->assertNotFound();
        $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => 5])->assertNotFound();
    }

    public function test_index_only_lists_shop_supplied_services_and_reflects_low_stock(): void
    {
        $this->loginAsSuperAdmin();
        Service::factory()->create(['allow_customer_supplied' => true, 'allow_shop_supplied' => false, 'name' => 'Bring Your Own Only']);
        $lowStock = Service::factory()->shopSupplied()->create(['name' => 'Low Stock Item', 'stock_qty' => 2, 'low_stock_threshold' => 5]);
        $wellStocked = Service::factory()->shopSupplied()->create(['name' => 'Well Stocked Item', 'stock_qty' => 20, 'low_stock_threshold' => 5]);

        $response = $this->getJson('/api/v1/admin/inventory');

        $response->assertOk();
        $response->assertJsonMissing(['name' => 'Bring Your Own Only']);
        $response->assertJsonFragment(['id' => $lowStock->id, 'is_low_stock' => true]);
        $response->assertJsonFragment(['id' => $wellStocked->id, 'is_low_stock' => false]);
    }

    public function test_stock_at_the_threshold_counts_as_low_stock(): void
    {
        $this->loginAsSuperAdmin();
        $atThreshold = Service::factory()->shopSupplied()->create(['stock_qty' => 5, 'low_stock_threshold' => 5]);

        $response = $this->getJson('/api/v1/admin/inventory');

        $response->assertOk();
        $response->assertJsonFragment(['id' => $atThreshold->id, 'is_low_stock' => true]);
    }

    public function test_zero_quantity_is_rejected(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 10]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", ['qty' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('qty');

        $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('change_qty');
    }

    public function test_log_records_the_acting_admin(): void
    {
        $admin = $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 0]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", ['qty' => 5])->assertOk();

        $this->assertDatabaseHas('inventory_logs', [
            'service_id' => $service->id,
            'created_by' => $admin->id,
        ]);
    }

    public function test_logs_are_listed_newest_first(): void
    {
        $this->loginAsSuperAdmin();
        $service = Service::factory()->shopSupplied()->create(['stock_qty' => 0]);

        $this->postJson("/api/v1/admin/inventory/{$service->id}/restock", ['qty' => 10])->assertOk();
        $this->postJson("/api/v1/admin/inventory/{$service->id}/adjust", ['change_qty' => -2])->assertOk();

        $response = $this->getJson('/api/v1/admin/inventory/logs');

        $response->assertOk();
        $reasons = collect($response->json('data'))->pluck('reason')->all();
        $this->assertSame(['adjust', 'restock'], $reasons);
    }
}
