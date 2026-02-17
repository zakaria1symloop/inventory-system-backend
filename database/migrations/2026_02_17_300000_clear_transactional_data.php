<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Clear transactional data - keep products, users, warehouses, categories, brands, units, suppliers, settings
        $tablesToClear = [
            'caisse_transfers',
            'caisse_settlements',
            'caisse_transactions',
            'caisses',
            'stock_movements',
            'adjustment_items',
            'adjustments',
            'sale_return_items',
            'sale_returns',
            'purchase_return_items',
            'purchase_returns',
            'purchase_order_items',
            'purchase_orders',
            'delivery_orders',
            'deliveries',
            'order_items',
            'orders',
            'trip_products',
            'trips',
            'sale_items',
            'payments',
            'sales',
            'purchase_items',
            'purchases',
            'van_sale_items',
            'van_sales',
            'product_request_items',
            'product_requests',
            'van_session_items',
            'van_sessions',
            'stock_transfer_items',
            'stock_transfers',
            'dispenses',
            'sync_logs',
            'stock',
        ];

        foreach ($tablesToClear as $table) {
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        // Reset client balances
        if (Schema::hasTable('clients')) {
            DB::table('clients')->update(['balance' => 0]);
        }

        // Reset supplier balances
        if (Schema::hasTable('suppliers')) {
            DB::table('suppliers')->update(['balance' => 0]);
        }

        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        // Cannot undo data clearing
    }
};
