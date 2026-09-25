<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_customer_can_update_profile(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');

        $response = $this->patchJson('/api/v1/profile', [
            'first_name' => 'Pedro',
            'middle_name' => 'Garcia',
            'last_name' => 'Reyes',
            'address' => '456 Bonifacio Ave, Quezon City',
        ]);

        $response->assertOk();
        $response->assertJson([
            'first_name' => 'Pedro',
            'middle_name' => 'Garcia',
            'last_name' => 'Reyes',
            'address' => '456 Bonifacio Ave, Quezon City',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
        ]);
    }

    public function test_profile_update_clears_middle_name(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');

        $response = $this->patchJson('/api/v1/profile', [
            'first_name' => 'Juan',
            'middle_name' => null,
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'middle_name' => null,
        ]);
    }

    public function test_profile_update_rejects_missing_required_fields(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');

        $response = $this->patchJson('/api/v1/profile', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['first_name', 'last_name', 'address']);
    }

    public function test_profile_update_rejects_unauthenticated_request(): void
    {
        $response = $this->patchJson('/api/v1/profile', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertUnauthorized();
    }

    public function test_profile_update_rejects_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');

        $response = $this->patchJson('/api/v1/profile', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertForbidden();
    }
}
