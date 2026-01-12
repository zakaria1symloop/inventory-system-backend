<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'المدير',
            'email' => 'admin@admin.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        // Create seller
        User::create([
            'name' => 'البائع',
            'email' => 'seller@admin.com',
            'password' => Hash::make('password'),
            'phone' => '0555000001',
            'role' => 'seller',
            'is_active' => true,
        ]);

        // Create livreur
        User::create([
            'name' => 'السائق',
            'email' => 'livreur@admin.com',
            'password' => Hash::make('password'),
            'phone' => '0555000002',
            'role' => 'livreur',
            'is_active' => true,
        ]);

        // Create warehouse
        $warehouse = Warehouse::create([
            'name' => 'المستودع الرئيسي',
            'address' => 'بسكرة',
            'is_main' => true,
            'is_active' => true,
        ]);

        // Create categories
        $categories = [
            'مشروبات',
            'حلويات',
            'منتجات ألبان',
            'مواد غذائية',
            'منظفات',
        ];

        foreach ($categories as $cat) {
            Category::create(['name' => $cat, 'is_active' => true]);
        }

        // Create brands
        $brands = [
            'كوكا كولا',
            'بيبسي',
            'نستله',
            'دانون',
            'أومو',
        ];

        foreach ($brands as $brand) {
            Brand::create(['name' => $brand, 'is_active' => true]);
        }

        // Create units
        $piece = Unit::create(['name' => 'قطعة', 'short_name' => 'قط', 'is_active' => true]);
        $carton = Unit::create([
            'name' => 'كرتون',
            'short_name' => 'كر',
            'base_unit_id' => $piece->id,
            'operator' => '*',
            'operation_value' => 12,
            'is_active' => true,
        ]);
        Unit::create(['name' => 'كيلوغرام', 'short_name' => 'كغ', 'is_active' => true]);
        Unit::create(['name' => 'لتر', 'short_name' => 'ل', 'is_active' => true]);

        // Create products
        $products = [
            ['name' => 'كوكا كولا 1.5 لتر', 'cost' => 80, 'retail' => 100, 'wholesale' => 95],
            ['name' => 'بيبسي 1.5 لتر', 'cost' => 75, 'retail' => 95, 'wholesale' => 90],
            ['name' => 'ياغورت دانون', 'cost' => 30, 'retail' => 40, 'wholesale' => 35],
            ['name' => 'حليب كندية 1 لتر', 'cost' => 100, 'retail' => 120, 'wholesale' => 115],
            ['name' => 'بسكويت أوريو', 'cost' => 120, 'retail' => 150, 'wholesale' => 140],
        ];

        foreach ($products as $index => $p) {
            $product = Product::create([
                'name' => $p['name'],
                'category_id' => ($index % 5) + 1,
                'brand_id' => ($index % 5) + 1,
                'unit_buy_id' => $carton->id,
                'unit_sale_id' => $piece->id,
                'barcode' => Product::generateBarcode(),
                'cost_price' => $p['cost'],
                'retail_price' => $p['retail'],
                'wholesale_price' => $p['wholesale'],
                'min_selling_price' => $p['cost'] + 5,
                'stock_alert' => 10,
                'is_active' => true,
            ]);

            Stock::create([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => rand(50, 200),
            ]);
        }

        // Create clients
        $clients = [
            ['name' => 'محل الأمل', 'phone' => '0555100001', 'address' => 'حي النصر، بسكرة'],
            ['name' => 'سوبرات الهدى', 'phone' => '0555100002', 'address' => 'حي الفتح، بسكرة'],
            ['name' => 'متجر البركة', 'phone' => '0555100003', 'address' => 'وسط المدينة، بسكرة'],
            ['name' => 'محل السعادة', 'phone' => '0555100004', 'address' => 'حي المجاهدين، بسكرة'],
            ['name' => 'سوبرات الخير', 'phone' => '0555100005', 'address' => 'حي 1000 مسكن، بسكرة'],
        ];

        foreach ($clients as $client) {
            Client::create([
                'name' => $client['name'],
                'phone' => $client['phone'],
                'address' => $client['address'],
                'credit_limit' => 50000,
                'is_active' => true,
            ]);
        }

        // Create suppliers
        $suppliers = [
            ['name' => 'شركة التوزيع الجزائرية', 'company' => 'SOTRADIS'],
            ['name' => 'مؤسسة الإخوة للتوزيع', 'company' => 'FRERES DIST'],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::create([
                'name' => $supplier['name'],
                'company_name' => $supplier['company'],
                'phone' => '021' . rand(100000, 999999),
                'is_active' => true,
            ]);
        }

        // Create vehicles
        Vehicle::create(['name' => 'شاحنة 1', 'plate_number' => '00001-100-07', 'is_active' => true]);
        Vehicle::create(['name' => 'شاحنة 2', 'plate_number' => '00002-100-07', 'is_active' => true]);
    }
}
