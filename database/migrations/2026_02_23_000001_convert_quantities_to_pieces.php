<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Convert quantity columns from DECIMAL (cartons) to INTEGER (pieces).
     *
     * Old system: quantity = cartons as decimal (e.g. 5.33 = 5 cartons + 4 pieces for ppp=12)
     * New system: quantity = total pieces as integer (e.g. 64 pieces)
     *
     * Conversion: new_qty = floor(old_qty) * ppp + round((old_qty - floor(old_qty)) * ppp)
     */
    public function up(): void
    {
        // Step 1: Convert data in each table before changing column types

        // --- stock table ---
        DB::statement("
            UPDATE stock
            SET quantity = FLOOR(quantity) * COALESCE(
                (SELECT pieces_per_package FROM products WHERE products.id = stock.product_id), 1
            ) + ROUND(
                (quantity - FLOOR(quantity)) * COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = stock.product_id), 1
                )
            )
        ");

        // --- sale_items table ---
        DB::statement("
            UPDATE sale_items
            SET quantity = FLOOR(quantity) * COALESCE(
                (SELECT pieces_per_package FROM products WHERE products.id = sale_items.product_id), 1
            ) + ROUND(
                (quantity - FLOOR(quantity)) * COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = sale_items.product_id), 1
                )
            )
        ");

        // --- purchase_items table ---
        DB::statement("
            UPDATE purchase_items
            SET quantity = FLOOR(quantity) * COALESCE(
                (SELECT pieces_per_package FROM products WHERE products.id = purchase_items.product_id), 1
            ) + ROUND(
                (quantity - FLOOR(quantity)) * COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = purchase_items.product_id), 1
                )
            )
        ");

        // --- order_items table ---
        $orderQtyColumns = ['quantity_ordered', 'quantity_confirmed', 'quantity_delivered', 'quantity_returned'];
        foreach ($orderQtyColumns as $col) {
            DB::statement("
                UPDATE order_items
                SET {$col} = FLOOR({$col}) * COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = order_items.product_id), 1
                ) + ROUND(
                    ({$col} - FLOOR({$col})) * COALESCE(
                        (SELECT pieces_per_package FROM products WHERE products.id = order_items.product_id), 1
                    )
                )
                WHERE {$col} IS NOT NULL
            ");
        }

        // --- stock_movements table ---
        $movementCols = ['quantity_before', 'quantity_change', 'quantity_after'];
        foreach ($movementCols as $col) {
            DB::statement("
                UPDATE stock_movements
                SET {$col} = FLOOR(ABS({$col})) * COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = stock_movements.product_id), 1
                ) + ROUND(
                    (ABS({$col}) - FLOOR(ABS({$col}))) * COALESCE(
                        (SELECT pieces_per_package FROM products WHERE products.id = stock_movements.product_id), 1
                    )
                )
                WHERE {$col} >= 0
            ");
            // Handle negative values (quantity_change can be negative for outgoing)
            DB::statement("
                UPDATE stock_movements
                SET {$col} = -(
                    FLOOR(ABS({$col})) * COALESCE(
                        (SELECT pieces_per_package FROM products WHERE products.id = stock_movements.product_id), 1
                    ) + ROUND(
                        (ABS({$col}) - FLOOR(ABS({$col}))) * COALESCE(
                            (SELECT pieces_per_package FROM products WHERE products.id = stock_movements.product_id), 1
                        )
                    )
                )
                WHERE {$col} < 0
            ");
        }

        // --- delivery_stock table ---
        $deliveryStockCols = ['quantity_loaded', 'quantity_delivered', 'quantity_returned'];
        foreach ($deliveryStockCols as $col) {
            DB::statement("
                UPDATE delivery_stock
                SET {$col} = FLOOR({$col}) * COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = delivery_stock.product_id), 1
                ) + ROUND(
                    ({$col} - FLOOR({$col})) * COALESCE(
                        (SELECT pieces_per_package FROM products WHERE products.id = delivery_stock.product_id), 1
                    )
                )
                WHERE {$col} IS NOT NULL
            ");
        }

        // --- products.opening_stock ---
        DB::statement("
            UPDATE products
            SET opening_stock = FLOOR(opening_stock) * COALESCE(pieces_per_package, 1) + ROUND(
                (opening_stock - FLOOR(opening_stock)) * COALESCE(pieces_per_package, 1)
            )
            WHERE opening_stock IS NOT NULL AND opening_stock > 0
        ");

        // Step 2: Change column types to INTEGER
        Schema::table('stock', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->integer('quantity')->default(0)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->integer('quantity_ordered')->default(0)->change();
            $table->integer('quantity_confirmed')->nullable()->change();
            $table->integer('quantity_delivered')->default(0)->change();
            $table->integer('quantity_returned')->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('quantity_before')->default(0)->change();
            $table->integer('quantity_change')->default(0)->change();
            $table->integer('quantity_after')->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->integer('opening_stock')->default(0)->change();
        });

        Schema::table('delivery_stock', function (Blueprint $table) {
            $table->integer('quantity_loaded')->default(0)->change();
            $table->integer('quantity_delivered')->default(0)->change();
            $table->integer('quantity_returned')->default(0)->change();
        });
    }

    /**
     * Reverse: Convert INTEGER (pieces) back to DECIMAL (cartons).
     */
    public function down(): void
    {
        // Change column types back to DECIMAL first
        Schema::table('stock', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(0)->change();
        });

        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(0)->change();
        });

        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('quantity', 12, 2)->default(0)->change();
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->decimal('quantity_ordered', 12, 2)->default(0)->change();
            $table->decimal('quantity_confirmed', 12, 2)->nullable()->change();
            $table->decimal('quantity_delivered', 12, 2)->default(0)->change();
            $table->decimal('quantity_returned', 12, 2)->default(0)->change();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('quantity_before', 12, 2)->default(0)->change();
            $table->decimal('quantity_change', 12, 2)->default(0)->change();
            $table->decimal('quantity_after', 12, 2)->default(0)->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->decimal('opening_stock', 12, 2)->default(0)->change();
        });

        // Convert data back: pieces → cartons (decimal)
        // new_decimal = floor(pieces / ppp) + (pieces % ppp) / ppp
        $tables = [
            ['stock', 'quantity', 'product_id'],
            ['sale_items', 'quantity', 'product_id'],
            ['purchase_items', 'quantity', 'product_id'],
        ];

        foreach ($tables as [$tableName, $col, $fk]) {
            DB::statement("
                UPDATE {$tableName}
                SET {$col} = FLOOR({$col} / COALESCE(
                    (SELECT pieces_per_package FROM products WHERE products.id = {$tableName}.{$fk}), 1
                )) + (
                    ({$col} % COALESCE(
                        (SELECT pieces_per_package FROM products WHERE products.id = {$tableName}.{$fk}), 1
                    )) / COALESCE(
                        (SELECT pieces_per_package FROM products WHERE products.id = {$tableName}.{$fk}), 1
                    )
                )
            ");
        }
    }
};
