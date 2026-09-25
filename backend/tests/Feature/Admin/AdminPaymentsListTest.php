<?php

namespace Tests\Feature\Admin;

use App\Models\Booking;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPaymentsListTest extends TestCase
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

    private function loginAsAdminWithoutPaymentsPermission(): User
    {
        $admin = User::factory()->admin()->create(['password' => 'correct-password']);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'correct-password',
        ])->assertNoContent();

        return $admin;
    }

    public function test_filtering_by_method(): void
    {
        $this->loginAsSuperAdmin();
        Payment::factory()->create(['method' => 'cash']);
        Payment::factory()->create(['method' => 'gcash']);
        Payment::factory()->create(['method' => 'card']);

        $response = $this->getJson('/api/v1/admin/payments?method=gcash');

        $response->assertOk();
        $methods = collect($response->json('data'))->pluck('method')->all();
        $this->assertSame(['gcash'], $methods);
    }

    public function test_searching_by_booking_code(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create(['code' => 'RS-9001']);
        Payment::factory()->create(['booking_id' => $booking->id]);
        Payment::factory()->create();

        $response = $this->getJson('/api/v1/admin/payments?search=RS-9001');

        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('booking_code')->all();
        $this->assertSame(['RS-9001'], $codes);
    }

    public function test_searching_by_guest_name_or_phone(): void
    {
        $this->loginAsSuperAdmin();
        $booking = Booking::factory()->create([
            'customer_id' => null,
            'guest_name' => 'Juan Dela Cruz',
            'guest_phone' => '09171234567',
        ]);
        Payment::factory()->create(['booking_id' => $booking->id]);
        Payment::factory()->create();

        $this->getJson('/api/v1/admin/payments?search=Dela Cruz')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/payments?search=09171234567')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_searching_by_customer_name_or_phone(): void
    {
        $this->loginAsSuperAdmin();
        $customer = User::factory()->create([
            'first_name' => 'Maria',
            'last_name' => 'Santos',
            'phone' => '09181234567',
        ]);
        $booking = Booking::factory()->create(['customer_id' => $customer->id]);
        Payment::factory()->create(['booking_id' => $booking->id]);
        Payment::factory()->create();

        $this->getJson('/api/v1/admin/payments?search=Maria Santos')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/v1/admin/payments?search=09181234567')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_results_are_paginated(): void
    {
        $this->loginAsSuperAdmin();
        Payment::factory()->count(20)->create();

        $response = $this->getJson('/api/v1/admin/payments');

        $response->assertOk();
        $this->assertLessThan(20, count($response->json('data')));
        $this->assertArrayHasKey('current_page', $response->json('meta'));
    }

    public function test_results_are_ordered_newest_first(): void
    {
        $this->loginAsSuperAdmin();
        $first = Payment::factory()->create();
        $second = Payment::factory()->create();

        $response = $this->getJson('/api/v1/admin/payments');

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$second->id, $first->id], $ids);
    }

    public function test_admin_without_payments_permission_is_forbidden(): void
    {
        $this->loginAsAdminWithoutPaymentsPermission();

        $this->getJson('/api/v1/admin/payments')->assertForbidden();
    }

    public function test_customer_is_forbidden(): void
    {
        $customer = User::factory()->create();
        $this->actingAs($customer);

        $this->getJson('/api/v1/admin/payments')->assertForbidden();
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/admin/payments')->assertUnauthorized();
    }
}
