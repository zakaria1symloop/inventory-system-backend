<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('app_version', 20)->default('1.0')->after('updates_enabled');
            $table->timestamp('last_updated_at')->nullable()->after('app_version');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['app_version', 'last_updated_at']);
        });
    }
};
