<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('delivery_returns', function (Blueprint $table) {
            $table->boolean('returnable_to_stock')->default(true)->after('reason');
            $table->decimal('unit_cost', 12, 2)->default(0)->after('returnable_to_stock');
            $table->decimal('loss_amount', 12, 2)->default(0)->after('unit_cost');
            $table->boolean('loss_recorded')->default(false)->after('loss_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('delivery_returns', function (Blueprint $table) {
            $table->dropColumn(['returnable_to_stock', 'unit_cost', 'loss_amount', 'loss_recorded']);
        });
    }
};
