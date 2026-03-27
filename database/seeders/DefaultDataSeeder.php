<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Brand;
use App\Models\Unit;

class DefaultDataSeeder extends Seeder
{
    public function run(): void
    {
        // Default product categories
        $categories = [
            'مشروبات',
            'حلويات',
            'منتجات ألبان',
            'مواد غذائية',
            'منظفات',
        ];

        foreach ($categories as $cat) {
            Category::firstOrCreate(['name' => $cat], ['is_active' => true]);
        }

        // Default units
        $piece = Unit::firstOrCreate(
            ['short_name' => 'قط'],
            ['name' => 'قطعة', 'is_active' => true]
        );
        Unit::firstOrCreate(
            ['short_name' => 'كر'],
            [
                'name' => 'كرتون',
                'base_unit_id' => $piece->id,
                'operator' => '*',
                'operation_value' => 12,
                'is_active' => true,
            ]
        );
        Unit::firstOrCreate(
            ['short_name' => 'كغ'],
            ['name' => 'كيلوغرام', 'is_active' => true]
        );
        Unit::firstOrCreate(
            ['short_name' => 'ل'],
            ['name' => 'لتر', 'is_active' => true]
        );
    }
}
