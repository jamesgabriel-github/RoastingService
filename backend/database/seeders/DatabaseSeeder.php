<?php

namespace Database\Seeders;

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
    }
}
