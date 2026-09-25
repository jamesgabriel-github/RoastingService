<?php

namespace Tests\Feature;

use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicesPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_only_active_services(): void
    {
        Service::factory()->create(['name' => 'Active One', 'is_active' => true]);
        Service::factory()->create(['name' => 'Inactive One', 'is_active' => false]);

        $response = $this->getJson('/api/v1/services');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Active One']);
        $response->assertJsonMissing(['name' => 'Inactive One']);
    }

    public function test_a_shop_supplied_service_with_no_stock_shows_in_stock_false(): void
    {
        Service::factory()->shopSupplied()->create(['name' => 'Empty Shelf', 'stock_qty' => 0]);

        $response = $this->getJson('/api/v1/services');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Empty Shelf', 'in_stock' => false]);
    }

    public function test_a_shop_supplied_service_with_stock_shows_in_stock_true(): void
    {
        Service::factory()->shopSupplied()->create(['name' => 'Stocked Item', 'stock_qty' => 5]);

        $response = $this->getJson('/api/v1/services');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Stocked Item', 'in_stock' => true]);
    }

    public function test_a_customer_supplied_only_service_has_a_null_in_stock_value(): void
    {
        Service::factory()->create(['name' => 'Bring Your Own']);

        $response = $this->getJson('/api/v1/services');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Bring Your Own', 'in_stock' => null]);
    }

    public function test_response_omits_stock_qty_and_is_active_keys(): void
    {
        Service::factory()->create();

        $response = $this->getJson('/api/v1/services');

        $response->assertOk();
        $service = $response->json()[0];
        $this->assertArrayNotHasKey('stock_qty', $service);
        $this->assertArrayNotHasKey('is_active', $service);
    }

    public function test_no_authentication_required(): void
    {
        Service::factory()->create();

        $this->getJson('/api/v1/services')->assertOk();
    }
}
