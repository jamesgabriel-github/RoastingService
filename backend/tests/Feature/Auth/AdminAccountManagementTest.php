<?php

namespace Tests\Feature\Auth;

use App\Models\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccountManagementTest extends TestCase
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

    private function loginAsAdmin(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    public function test_super_admin_can_list_admin_accounts(): void
    {
        $actingSuperAdmin = $this->loginAsSuperAdmin();
        User::factory()->admin()->create(['name' => 'Existing Admin']);
        $otherSuperAdmin = User::factory()->superAdmin()->create(['name' => 'Other Super Admin']);
        $customer = User::factory()->create(['name' => 'A Customer']);

        $response = $this->getJson('/api/v1/admin/accounts');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Existing Admin']);
        $response->assertJsonCount(1);
        $response->assertJsonMissing(['name' => $actingSuperAdmin->name]);
        $response->assertJsonMissing(['name' => $otherSuperAdmin->name]);
        $response->assertJsonMissing(['name' => $customer->name]);
    }

    public function test_super_admin_can_create_admin_account_with_permissions(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/accounts', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'password123',
            'permissions' => ['services', 'inventory'],
        ]);

        $response->assertCreated();
        $response->assertJson([
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'role' => 'admin',
        ]);
        $this->assertDatabaseHas('users', ['email' => 'new-admin@example.com', 'role' => 'admin']);
        $newAdmin = User::where('email', 'new-admin@example.com')->first();
        $this->assertEqualsCanonicalizing(
            ['services', 'inventory'],
            $newAdmin->permissions()->pluck('module')->all()
        );
    }

    public function test_admin_account_creation_rejects_duplicate_email(): void
    {
        $this->loginAsSuperAdmin();
        User::factory()->admin()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/v1/admin/accounts', [
            'name' => 'New Admin',
            'email' => 'taken@example.com',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_admin_account_creation_rejects_duplicate_permission_module(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/accounts', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'password123',
            'permissions' => ['services', 'services'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('permissions.0');
    }

    public function test_admin_account_creation_rejects_unknown_permission_module(): void
    {
        $this->loginAsSuperAdmin();

        $response = $this->postJson('/api/v1/admin/accounts', [
            'name' => 'New Admin',
            'email' => 'new-admin@example.com',
            'password' => 'password123',
            'permissions' => ['not-a-real-module'],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('permissions.0');
    }

    public function test_super_admin_can_disable_an_admin_account(): void
    {
        $this->loginAsSuperAdmin();
        $admin = User::factory()->admin()->create();

        $response = $this->patchJson("/api/v1/admin/accounts/{$admin->id}", [
            'is_active' => false,
        ]);

        $response->assertOk();
        $this->assertFalse($admin->fresh()->is_active);
    }

    public function test_disabled_admin_is_blocked_from_admin_routes_even_with_a_live_session(): void
    {
        // Simulates an admin whose account was disabled while their session
        // was still active - the role middleware re-checks is_active on
        // every request, not just at login.
        $admin = User::factory()->admin()->create(['is_active' => false]);
        $this->actingAs($admin);

        $this->getJson('/api/v1/admin/accounts')->assertForbidden();
    }

    public function test_super_admin_can_replace_admin_permissions(): void
    {
        $superAdmin = $this->loginAsSuperAdmin();
        $admin = User::factory()->admin()->create();
        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'services',
            'granted_by' => $superAdmin->id,
        ]);

        $response = $this->patchJson("/api/v1/admin/accounts/{$admin->id}", [
            'permissions' => ['payments', 'dashboard'],
        ]);

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['payments', 'dashboard'],
            $admin->permissions()->pluck('module')->all()
        );
    }

    public function test_plain_admin_is_forbidden_from_all_admin_account_endpoints(): void
    {
        $admin = $this->loginAsAdmin();
        $otherAdmin = User::factory()->admin()->create();

        $this->getJson('/api/v1/admin/accounts')->assertForbidden();
        $this->postJson('/api/v1/admin/accounts', [
            'name' => 'X',
            'email' => 'x@example.com',
            'password' => 'password123',
        ])->assertForbidden();
        $this->patchJson("/api/v1/admin/accounts/{$otherAdmin->id}", ['is_active' => false])
            ->assertForbidden();
        $this->patchJson("/api/v1/admin/accounts/{$admin->id}", ['is_active' => false])
            ->assertForbidden();
    }

    public function test_admin_account_endpoints_cannot_target_a_super_admin_row(): void
    {
        $this->loginAsSuperAdmin();
        $otherSuperAdmin = User::factory()->superAdmin()->create();

        $this->patchJson("/api/v1/admin/accounts/{$otherSuperAdmin->id}", ['is_active' => false])
            ->assertNotFound();
    }
}
