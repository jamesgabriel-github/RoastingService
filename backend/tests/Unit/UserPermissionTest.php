<?php

namespace Tests\Unit;

use App\Models\AdminPermission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_has_every_module_permission(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        foreach (User::MODULES as $module) {
            $this->assertTrue($superAdmin->hasModulePermission($module));
        }
    }

    public function test_admin_only_has_granted_module_permissions(): void
    {
        $admin = User::factory()->admin()->create();
        $granter = User::factory()->superAdmin()->create();

        AdminPermission::create([
            'user_id' => $admin->id,
            'module' => 'services',
            'granted_by' => $granter->id,
        ]);

        $this->assertTrue($admin->hasModulePermission('services'));
        $this->assertFalse($admin->hasModulePermission('inventory'));
    }

    public function test_customer_has_no_module_permissions(): void
    {
        $customer = User::factory()->create();

        foreach (User::MODULES as $module) {
            $this->assertFalse($customer->hasModulePermission($module));
        }
    }

    public function test_disabled_super_admin_has_no_module_permissions(): void
    {
        $disabledSuperAdmin = User::factory()->superAdmin()->create(['is_active' => false]);

        foreach (User::MODULES as $module) {
            $this->assertFalse($disabledSuperAdmin->hasModulePermission($module));
        }
    }

    public function test_disabled_admin_has_no_module_permissions_even_when_granted(): void
    {
        $granter = User::factory()->superAdmin()->create();
        $disabledAdmin = User::factory()->admin()->create(['is_active' => false]);

        AdminPermission::create([
            'user_id' => $disabledAdmin->id,
            'module' => 'services',
            'granted_by' => $granter->id,
        ]);

        $this->assertFalse($disabledAdmin->hasModulePermission('services'));
    }
}
