<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => strtolower((string) config('roasting.admin_email'))],
            [
                'name' => 'Super Admin',
                'role' => 'super_admin',
                'password' => config('roasting.admin_password'),
            ]
        );

        $this->seedSampleServices();
    }

    private function seedSampleServices(): void
    {
        Service::firstOrCreate(['name' => 'Lechon Head'], [
            'description' => 'Whole roasted pig head, customer supplies the raw item.',
            'roasting_rate_per_kg' => 180.00,
            'shop_price' => null,
            'est_minutes' => 240,
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => false,
        ]);

        Service::firstOrCreate(['name' => 'Whole Turkey'], [
            'description' => 'Roasted whole turkey, bring your own or buy from stock.',
            'roasting_rate_per_kg' => 200.00,
            'shop_price' => 1800.00,
            'est_minutes' => 180,
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => true,
        ]);

        Service::firstOrCreate(['name' => 'Liempo'], [
            'description' => 'Roasted pork belly, customer supplies the raw item.',
            'roasting_rate_per_kg' => 150.00,
            'shop_price' => null,
            'est_minutes' => 90,
            'allow_customer_supplied' => true,
            'allow_shop_supplied' => false,
        ]);

        Service::firstOrCreate(['name' => 'Roast Chicken'], [
            'description' => 'Ready-roasted whole chicken from shop stock.',
            'roasting_rate_per_kg' => null,
            'shop_price' => 350.00,
            'est_minutes' => 60,
            'allow_customer_supplied' => false,
            'allow_shop_supplied' => true,
        ]);
    }
}
