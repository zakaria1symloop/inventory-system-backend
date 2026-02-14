<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // For MySQL, we need to modify the enum
        // First get current enum values and add new ones
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('purchase', 'purchase_return', 'sale', 'sale_return', 'adjustment', 'transfer', 'delivery', 'delivery_out', 'delivery_return', 'opening', 'order', 'van_out', 'van_sale', 'van_return') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_movements MODIFY COLUMN type ENUM('purchase', 'purchase_return', 'sale', 'sale_return', 'adjustment', 'transfer', 'delivery', 'delivery_out', 'delivery_return', 'opening', 'order') NOT NULL");
    }
};
