<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('movable_type')->nullable()->change();
            $table->unsignedBigInteger('movable_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('movable_type')->nullable(false)->change();
            $table->unsignedBigInteger('movable_id')->nullable(false)->change();
        });
    }
};
