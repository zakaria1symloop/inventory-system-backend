<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('timbre', 12, 2)->default(0)->after('shipping');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->decimal('timbre', 12, 2)->default(0)->after('shipping');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('timbre');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('timbre');
        });
    }
};
