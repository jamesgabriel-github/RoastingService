<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeding_creates_exactly_one_super_admin_with_configured_credentials(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('role', 'super_admin')->count());

        $superAdmin = User::where('role', 'super_admin')->first();
        $this->assertSame(config('roasting.admin_email'), $superAdmin->email);
        $this->assertTrue(Hash::check(config('roasting.admin_password'), $superAdmin->password));
    }

    public function test_seeding_twice_still_produces_exactly_one_super_admin(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(1, User::where('role', 'super_admin')->count());
    }

    public function test_seeding_creates_the_sample_services(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertEqualsCanonicalizing(
            ['Lechon Head', 'Whole Turkey', 'Liempo', 'Roast Chicken'],
            Service::pluck('name')->all()
        );
    }

    public function test_seeding_twice_still_produces_exactly_four_services(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, Service::count());
    }
}
