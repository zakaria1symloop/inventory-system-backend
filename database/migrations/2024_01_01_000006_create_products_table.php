<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_buy_id')->nullable()->constrained('units')->nullOnDelete();
            $table->foreignId('unit_sale_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('barcode')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('product_unit')->nullable();
            $table->integer('stock_alert')->default(0);
            $table->decimal('cost_price', 12, 2)->default(0);
            $table->decimal('retail_price', 12, 2)->default(0);
            $table->decimal('wholesale_price', 12, 2)->default(0);
            $table->decimal('min_selling_price', 12, 2)->default(0);
            $table->decimal('tax_percent', 5, 2)->default(0);
            $table->enum('tax_type', ['exclusive', 'inclusive'])->default('exclusive');
            $table->enum('discount_type', ['percent', 'fixed'])->default('percent');
            $table->decimal('discount_value', 12, 2)->default(0);
            $table->integer('points')->default(0);
            $table->decimal('opening_stock', 12, 2)->default(0);
            $table->string('image')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
