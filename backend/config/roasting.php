<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Seeded super admin credentials
    |--------------------------------------------------------------------------
    |
    | Used only by the database seeder to create the single v1 super admin
    | account. No public admin signup exists - this is the sole way an admin
    | identity enters the system, until a super admin creates more via the
    | admin-accounts endpoints.
    |
    */

    'admin_email' => env('ADMIN_EMAIL', 'admin@example.com'),
    'admin_password' => env('ADMIN_PASSWORD', 'password'),

];
