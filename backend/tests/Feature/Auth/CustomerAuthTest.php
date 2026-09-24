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

    public function test_customer_can_register(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'password' => 'password123',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'role' => 'customer',
            'permissions' => [],
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'role' => 'customer',
        ]);
        $this->assertAuthenticated('web');
    }

    public function test_registration_normalizes_intl_phone_format(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '+639171234567',
            'password' => 'password123',
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '09171234567']);
    }

    public function test_registration_rejects_invalid_phone_format(): void
    {
        $response = $this->postJson('/api/v1/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '12345',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('phone');
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'juan@example.com']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_duplicate_email_case_insensitively(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'Juan@example.com',
            'phone' => '09171234567',
            'password' => 'password123',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Another Juan',
            'email' => 'juan@example.com',
            'phone' => '09179876543',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_duplicate_phone(): void
    {
        User::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/register', [
            'name' => 'Juan Dela Cruz',
            'email' => 'juan@example.com',
            'phone' => '09171234567',
            'password' => 'password123',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('phone');
    }

    public function test_customer_can_log_in_with_registered_phone(): void
    {
        $customer = User::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/login', ['phone' => '09171234567']);

        $response->assertNoContent();
        $this->assertAuthenticatedAs($customer, 'web');
    }

    public function test_customer_can_log_in_with_intl_phone_format(): void
    {
        $customer = User::factory()->create(['phone' => '09171234567']);

        $response = $this->postJson('/api/v1/login', ['phone' => '+639171234567']);

        $response->assertNoContent();
        $this->assertAuthenticatedAs($customer, 'web');
    }

    public function test_login_rejects_unknown_phone(): void
    {
        $response = $this->postJson('/api/v1/login', ['phone' => '09171234567']);

        $response->assertUnprocessable();
        $this->assertGuest('web');
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

        $this->postJson('/api/v1/login', ['phone' => '09171234567'])->assertNoContent();

        $response = $this->postJson('/api/v1/logout');

        $response->assertNoContent();
        $this->assertGuest('web');
    }
}
