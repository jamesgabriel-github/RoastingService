<?php

namespace Tests\Feature\Auth;

use App\Models\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_unauthenticated_request_gets_401(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_customer_sees_own_profile_and_no_permissions(): void
    {
        $customer = User::factory()->create();
        $this->postJson('/api/v1/login', ['phone' => $customer->phone])->assertNoContent();

        $response = $this->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJson([
            'id' => $customer->id,
            'role' => 'customer',
            'permissions' => [],
        ]);
    }

    public function test_admin_sees_only_granted_module_permissions(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $superAdmin = User::factory()->superAdmin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'services',
            'granted_by' => $superAdmin->id,
        ]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        $response = $this->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJson([
            'id' => $admin->id,
            'role' => 'admin',
            'permissions' => ['services'],
        ]);
    }

    public function test_super_admin_sees_every_module_permission(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $superAdmin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        $response = $this->getJson('/api/v1/me');

        $response->assertOk();
        $response->assertJson([
            'id' => $superAdmin->id,
            'role' => 'super_admin',
            'permissions' => User::MODULES,
        ]);
    }
}
