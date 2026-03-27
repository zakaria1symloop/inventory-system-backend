<?php

namespace Database\Seeders;

use App\Models\SaasAdmin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SaasAdminSeeder extends Seeder
{
    public function run(): void
    {
        SaasAdmin::updateOrCreate(
            ['email' => 'admin@symloop.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('password'),
                'is_active' => true,
            ]
        );
    }
}
