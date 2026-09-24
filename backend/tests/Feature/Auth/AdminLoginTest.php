<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminLoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only starts a session for requests it recognizes as coming
        // from the SPA (matched against config('sanctum.stateful')).
        $this->withHeader('Referer', 'http://localhost:5173');
    }

    public function test_admin_can_log_in_with_correct_credentials(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ]);

        $response->assertNoContent();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_super_admin_can_log_in_with_correct_credentials(): void
    {
        $superAdmin = User::factory()->superAdmin()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $superAdmin->email,
            'password' => 'correct-password',
        ]);

        $response->assertNoContent();
        $this->assertAuthenticatedAs($superAdmin);
    }

    public function test_admin_can_log_in_with_different_case_email(): void
    {
        $admin = User::factory()->admin()->create([
            'email' => 'admin@example.com',
            'password' => 'correct-password',
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => 'Admin@Example.com',
            'password' => 'correct-password',
        ]);

        $response->assertNoContent();
        $this->assertAuthenticatedAs($admin);
    }

    public function test_admin_login_rejects_wrong_password(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_admin_login_rejects_disabled_account(): void
    {
        $admin = User::factory()->admin()->create([
            'password' => 'correct-password',
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_admin_login_rejects_customer_role(): void
    {
        $customer = User::factory()->create(['password' => 'correct-password']);

        $response = $this->postJson('/api/v1/admin/login', [
            'email' => $customer->email,
            'password' => 'correct-password',
        ]);

        $response->assertUnprocessable();
        $this->assertGuest();
    }

    public function test_admin_login_rejects_unknown_email_with_same_generic_error(): void
    {
        $unknown = $this->postJson('/api/v1/admin/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]);

        $admin = User::factory()->admin()->create(['password' => 'correct-password']);
        $wrongPassword = $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'wrong-password',
        ]);

        $this->assertSame(
            $unknown->json('errors.email'),
            $wrongPassword->json('errors.email')
        );
    }

    public function test_admin_can_log_out(): void
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();
        $this->assertAuthenticatedAs($admin);

        $response = $this->postJson('/api/v1/admin/logout');

        $response->assertNoContent();
        // The prior auth:sanctum check flips the app's default guard to
        // "sanctum" for the rest of this (simulated, same-process) request
        // lifecycle, so assert the "web" guard our controller actually logs
        // out, not whatever the default resolves to.
        $this->assertGuest('web');
    }
}
