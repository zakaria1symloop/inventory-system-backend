<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->decimal('cost_price', 12, 2)->default(0)->after('unit_price');
        });

        // Backfill existing sale items with product cost_price
        DB::table('sale_items')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->update(['sale_items.cost_price' => DB::raw('products.cost_price')]);
    }

    public function down(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('cost_price');
        });
    }
};
