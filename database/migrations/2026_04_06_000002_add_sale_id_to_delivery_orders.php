<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            if (!Schema::hasColumn('delivery_orders', 'sale_id')) {
                $table->unsignedBigInteger('sale_id')->nullable()->after('order_id');
                $table->index('sale_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('delivery_orders', function (Blueprint $table) {
            if (Schema::hasColumn('delivery_orders', 'sale_id')) {
                $table->dropIndex(['sale_id']);
                $table->dropColumn('sale_id');
            }
        });
    }
};
