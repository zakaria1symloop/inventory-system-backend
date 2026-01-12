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
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['purchase', 'purchase_return', 'sale', 'sale_return', 'adjustment', 'transfer', 'delivery', 'delivery_out', 'delivery_return', 'opening']);
            $table->string('reference')->nullable();
            $table->morphs('movable'); // For polymorphic relation (purchase, sale, adjustment, etc.)
            $table->decimal('quantity_before', 12, 2)->default(0);
            $table->decimal('quantity_change', 12, 2);
            $table->decimal('quantity_after', 12, 2);
            $table->decimal('unit_cost', 12, 2)->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'warehouse_id']);
            $table->index('type');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
