<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->decimal('gps_lat', 10, 8)->nullable();
            $table->decimal('gps_lng', 11, 8)->nullable();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            // Legal information
            $table->string('rc')->nullable(); // Commercial Register
            $table->string('nif')->nullable(); // Tax Identification Number
            $table->string('ai')->nullable(); // Article Number
            $table->string('nis')->nullable(); // Statistical Number
            $table->string('rib')->nullable(); // Bank Account Number
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
