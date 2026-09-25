<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_customer_can_log_in_with_registered_phone(): void
    {
        $customer = User::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/login', ['phone' => '09171234567']);

        $response->assertOk();
        $response->assertJson([
            'id' => $customer->id,
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        $this->assertAuthenticatedAs($customer, 'web');
    }

    public function test_customer_can_log_in_with_intl_phone_format(): void
    {
        $customer = User::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/login', ['phone' => '+639171234567']);

        $response->assertOk();
        $response->assertJson(['id' => $customer->id]);
        $this->assertAuthenticatedAs($customer, 'web');
    }

    public function test_login_auto_creates_customer_for_unknown_phone(): void
    {
        $response = $this->postJson('/api/v1/login', ['phone' => '09171234567']);

        $response->assertOk();
        $response->assertJson([
            'phone' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
            'first_name' => null,
            'last_name' => null,
        ]);
        $this->assertDatabaseHas('users', [
            'phone' => '09171234567',
            'role' => 'customer',
            'is_active' => true,
        ]);
        $this->assertAuthenticated('web');
    }

    public function test_login_auto_creates_customer_with_intl_phone_format(): void
    {
        $response = $this->postJson('/api/v1/login', ['phone' => '+639171234567']);

        $response->assertOk();
        $this->assertDatabaseHas('users', [
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        $this->assertAuthenticated('web');
    }

    public function test_login_rejects_admin_phone(): void
    {
        $admin = User::factory()->admin()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/login', ['phone' => $admin->phone]);

        $response->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_login_rejects_disabled_customer(): void
    {
        User::factory()->create(['phone' => '09171234567', 'is_active' => false]);

        $response = $this->postJson('/api/v1/login', ['phone' => '09171234567']);

        $response->assertUnprocessable();
        $this->assertGuest('web');
    }

    public function test_customer_can_log_out(): void
    {
        $customer = User::factory()->create(['phone' => '09171234567']);

        $this->postJson('/api/v1/login', ['phone' => '09171234567'])->assertOk();

        $response = $this->postJson('/api/v1/logout');

        $response->assertNoContent();
        $this->assertGuest('web');
    }
}
