<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('livreur_id')->constrained('users');
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->timestamp('start_time')->nullable();
            $table->timestamp('end_time')->nullable();
            $table->enum('status', ['preparing', 'in_progress', 'completed', 'cancelled'])->default('preparing');
            $table->integer('total_orders')->default(0);
            $table->integer('delivered_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('client_id')->constrained();
            $table->integer('delivery_order')->default(0);
            $table->enum('status', ['pending', 'delivered', 'partial', 'failed', 'postponed'])->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->text('failure_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_returns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity', 12, 2);
            $table->enum('reason', ['refused', 'damaged', 'excess', 'store_closed', 'other'])->default('other');
            $table->text('notes')->nullable();
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('delivery_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->decimal('quantity_loaded', 12, 2)->default(0);
            $table->decimal('quantity_delivered', 12, 2)->default(0);
            $table->decimal('quantity_returned', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_stock');
        Schema::dropIfExists('delivery_returns');
        Schema::dropIfExists('delivery_orders');
        Schema::dropIfExists('deliveries');
    }
};
