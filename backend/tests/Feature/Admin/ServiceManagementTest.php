<?php

namespace Tests\Feature\Admin;

use App\Models\AdminPermission;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceManagementTest extends TestCase
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

    private function loginAsAdminWithServicesPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $granter = User::factory()->superAdmin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'services',
            'granted_by' => $granter->id,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    private function loginAsAdminWithoutServicesPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Roast Duck',
            'description' => 'Whole roasted duck.',
            'est_minutes' => 120,
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => false,
            'roasting_rate_per_kg' => 175.50,
            'shop_price' => null,
            'low_stock_threshold' => 5,
        ], $overrides);
    }

    public function test_super_admin_can_list_create_update_and_toggle_a_service(): void
    {
        $this->loginAsSuperAdmin();

        $create = $this->postJson('/api/v1/admin/services', $this->validPayload());
        $create->assertCreated();
        $create->assertJson(['name' => 'Roast Duck', 'is_active' => true]);
        $id = $create->json('id');

        $this->getJson('/api/v1/admin/services')->assertOk()->assertJsonFragment(['name' => 'Roast Duck']);

        $update = $this->putJson("/api/v1/admin/services/{$id}", $this->validPayload(['est_minutes' => 150]));
        $update->assertOk();
        $update->assertJson(['est_minutes' => 150]);

        $toggle = $this->patchJson("/api/v1/admin/services/{$id}/toggle");
        $toggle->assertOk();
        $toggle->assertJson(['is_active' => false]);
    }

    public function test_admin_with_services_permission_can_manage_services(): void
    {
        $this->loginAsAdminWithServicesPermission();

        $this->postJson('/api/v1/admin/services', $this->validPayload())->assertCreated();
        $this->getJson('/api/v1/admin/services')->assertOk();
    }

    public function test_admin_without_services_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutServicesPermission();
        $service = Service::factory()->create();

        $this->getJson('/api/v1/admin/services')->assertForbidden();
        $this->postJson('/api/v1/admin/services', $this->validPayload())->assertForbidden();
        $this->putJson("/api/v1/admin/services/{$service->id}", $this->validPayload())->assertForbidden();
        $this->patchJson("/api/v1/admin/services/{$service->id}/toggle")->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->getJson('/api/v1/admin/services')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/services')->assertUnauthorized();
    }

    public function test_missing_rate_is_rejected_when_customer_supplied_is_enabled(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/services', $this->validPayload([
            'allow_customer_supplied' => true,
            'roasting_rate_per_kg' => null,
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('roasting_rate_per_kg');
    }

    public function test_missing_price_is_rejected_when_shop_supplied_is_enabled(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/services', $this->validPayload([
            'allow_customer_supplied' => false,
            'allow_shop_supplied' => true,
            'roasting_rate_per_kg' => null,
            'shop_price' => null,
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('shop_price');
    }

    public function test_both_booking_types_disabled_is_rejected(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/services', $this->validPayload([
            'allow_customer_supplied' => false,
            'allow_shop_supplied' => false,
            'roasting_rate_per_kg' => null,
            'shop_price' => null,
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('allow_shop_supplied');
    }

    public function test_creating_a_customer_supplied_only_service_nulls_out_a_submitted_shop_price(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/services', $this->validPayload([
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => false,
            'roasting_rate_per_kg' => 175.50,
            'shop_price' => 999.99,
        ]));

        $response->assertCreated();
        $response->assertJson(['shop_price' => null]);
        $this->assertDatabaseHas('services', ['name' => 'Roast Duck', 'shop_price' => null]);
    }

    public function test_stock_qty_in_the_request_body_is_ignored_on_create(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/services', $this->validPayload() + ['stock_qty' => 500]);

        $response->assertCreated();
        $response->assertJson(['stock_qty' => 0]);
    }

    public function test_low_stock_threshold_is_saved_on_create_and_update(): void
    {
        $this->loginAsSuperAdmin();

        $create = $this->postJson('/api/v1/admin/services', $this->validPayload(['low_stock_threshold' => 8]));
        $create->assertCreated();
        $create->assertJson(['low_stock_threshold' => 8]);
        $id = $create->json('id');

        $update = $this->putJson("/api/v1/admin/services/{$id}", $this->validPayload(['low_stock_threshold' => 12]));
        $update->assertOk();
        $update->assertJson(['low_stock_threshold' => 12]);
        $this->assertDatabaseHas('services', ['id' => $id, 'low_stock_threshold' => 12]);
    }
}
