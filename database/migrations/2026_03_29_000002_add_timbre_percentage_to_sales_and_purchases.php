<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('sales', 'timbre_percentage')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->decimal('timbre_percentage', 5, 2)->default(0)->after('timbre');
            });
        }
        if (!Schema::hasColumn('purchases', 'timbre_percentage')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->decimal('timbre_percentage', 5, 2)->default(0)->after('timbre');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('timbre_percentage');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropColumn('timbre_percentage');
        });
    }
};
