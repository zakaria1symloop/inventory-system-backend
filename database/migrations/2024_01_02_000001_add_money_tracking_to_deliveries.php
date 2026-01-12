<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->decimal('total_amount', 12, 2)->default(0)->after('failed_count');
            $table->decimal('collected_amount', 12, 2)->default(0)->after('total_amount');
            $table->foreignId('warehouse_id')->nullable()->after('vehicle_id')->constrained()->nullOnDelete();
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->decimal('amount_due', 12, 2)->default(0)->after('status');
            $table->decimal('amount_collected', 12, 2)->default(0)->after('amount_due');
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn(['total_amount', 'collected_amount', 'warehouse_id']);
        });

        Schema::table('delivery_orders', function (Blueprint $table) {
            $table->dropColumn(['amount_due', 'amount_collected']);
        });
    }
};
