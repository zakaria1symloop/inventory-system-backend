<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Van Session - when driver loads products and goes out to sell
        Schema::create('van_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('livreur_id')->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('warehouse_id')->constrained();
            $table->date('date');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->enum('status', ['preparing', 'active', 'completed', 'cancelled'])->default('preparing');
            $table->decimal('total_loaded_value', 12, 2)->default(0); // Value of products loaded
            $table->decimal('total_sales', 12, 2)->default(0); // Total sales amount
            $table->decimal('total_collected', 12, 2)->default(0); // Cash collected
            $table->decimal('total_credit', 12, 2)->default(0); // Sales on credit
            $table->decimal('total_returned_value', 12, 2)->default(0); // Value of returned products
            $table->integer('sales_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Products loaded on van
        Schema::create('van_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('van_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity_loaded', 12, 2)->default(0);
            $table->decimal('quantity_sold', 12, 2)->default(0);
            $table->decimal('quantity_returned', 12, 2)->default(0);
            $table->decimal('unit_cost', 12, 2)->default(0); // Cost price at loading time
            $table->timestamps();

            $table->unique(['van_session_id', 'product_id']);
        });

        // Individual sales made from van
        Schema::create('van_sales', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('van_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete(); // null = cash sale
            $table->timestamp('sale_time');
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('due_amount', 12, 2)->default(0);
            $table->enum('payment_status', ['paid', 'partial', 'unpaid'])->default('paid');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Items in each van sale
        Schema::create('van_sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('van_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2);
            $table->timestamps();
        });

        // Returns at end of session
        Schema::create('van_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('van_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->enum('reason', ['unsold', 'damaged', 'expired', 'other'])->default('unsold');
            $table->boolean('returnable_to_stock')->default(true);
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('van_returns');
        Schema::dropIfExists('van_sale_items');
        Schema::dropIfExists('van_sales');
        Schema::dropIfExists('van_session_items');
        Schema::dropIfExists('van_sessions');
    }
};
