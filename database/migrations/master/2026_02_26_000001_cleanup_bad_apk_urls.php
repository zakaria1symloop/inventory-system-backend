<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Clean up bad APK URLs (bucket root URL saved from failed uploads)
        DB::table('app_versions')->update([
            'driver_apk_url' => null,
            'driver_apk_version' => null,
            'sales_apk_url' => null,
            'sales_apk_version' => null,
            'cashvan_apk_url' => null,
            'cashvan_apk_version' => null,
        ]);
    }

    public function down(): void
    {
        // No rollback needed
    }
};
