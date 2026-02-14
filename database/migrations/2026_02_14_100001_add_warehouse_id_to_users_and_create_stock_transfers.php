<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add warehouse_id to users (for cashvan drivers)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('role')->constrained()->nullOnDelete();
        });

        // Stock transfers between warehouses (pending -> collected)
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('from_warehouse_id')->constrained('warehouses');
            $table->foreignId('to_warehouse_id')->constrained('warehouses');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'collected'])->default('pending');
            $table->timestamp('collected_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['to_warehouse_id', 'status']);
            $table->index('created_at');
        });

        // Items in each transfer
        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->timestamps();

            $table->unique(['stock_transfer_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn('warehouse_id');
        });
    }
};
