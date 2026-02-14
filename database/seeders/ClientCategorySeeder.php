<?php

namespace Database\Seeders;

use App\Models\ClientCategory;
use Illuminate\Database\Seeder;

class ClientCategorySeeder extends Seeder
{
    public function run(): void
    {
        ClientCategory::firstOrCreate(
            ['name' => 'Grossiste'],
            ['description' => 'تاجر جملة', 'is_default' => false]
        );

        ClientCategory::firstOrCreate(
            ['name' => 'Detaillant'],
            ['description' => 'تاجر تجزئة', 'is_default' => true]
        );
    }
}
