<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('purchase', 'purchase_return', 'sale', 'sale_return', 'adjustment', 'transfer', 'transfer_in', 'delivery', 'delivery_out', 'delivery_return', 'opening', 'order', 'van_out', 'van_sale', 'van_return')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('purchase', 'purchase_return', 'sale', 'sale_return', 'adjustment', 'transfer', 'delivery', 'delivery_out', 'delivery_return', 'opening', 'order', 'van_out', 'van_sale', 'van_return')");
    }
};
