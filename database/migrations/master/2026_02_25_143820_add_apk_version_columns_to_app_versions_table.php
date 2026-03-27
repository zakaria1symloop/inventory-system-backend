<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->string('driver_apk_version')->nullable()->after('driver_apk_url');
            $table->string('sales_apk_version')->nullable()->after('sales_apk_url');
            $table->string('cashvan_apk_version')->nullable()->after('cashvan_apk_url');

            // Make legacy columns nullable (single-row usage)
            $table->string('version')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('app_versions', function (Blueprint $table) {
            $table->dropColumn(['driver_apk_version', 'sales_apk_version', 'cashvan_apk_version']);
        });
    }
};
