<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_customer_can_complete_profile(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');

        $response = $this->postJson('/api/v1/profile/complete', [
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertOk();
        $response->assertJson([
            'first_name' => 'Juan',
            'middle_name' => 'Santos',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
    }

    public function test_profile_completion_accepts_omitted_middle_name(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');

        $response = $this->postJson('/api/v1/profile/complete', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'first_name' => 'Juan',
            'middle_name' => null,
        ]);
    }

    public function test_profile_completion_rejects_missing_required_fields(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer, 'web');

        $response = $this->postJson('/api/v1/profile/complete', []);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['first_name', 'last_name', 'address']);
    }

    public function test_profile_completion_rejects_unauthenticated_request(): void
    {
        $response = $this->postJson('/api/v1/profile/complete', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertUnauthorized();
    }

    public function test_profile_completion_rejects_admin(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin, 'web');

        $response = $this->postJson('/api/v1/profile/complete', [
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'address' => '123 Rizal St, Manila',
        ]);

        $response->assertForbidden();
    }

    public function test_profile_completion_rejects_when_already_complete(): void
    {
        $customer = User::factory()->completeProfile()->create();
        $this->actingAs($customer, 'web');

        $response = $this->postJson('/api/v1/profile/complete', [
            'first_name' => 'Pedro',
            'last_name' => 'Reyes',
            'address' => '456 Bonifacio Ave, Quezon City',
        ]);

        $response->assertStatus(409);
        $this->assertDatabaseHas('users', [
            'id' => $customer->id,
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
        ]);
    }
}
