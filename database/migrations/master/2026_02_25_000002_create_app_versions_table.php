<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version')->unique();
            $table->json('modules')->nullable();
            $table->string('driver_apk_url')->nullable();
            $table->string('sales_apk_url')->nullable();
            $table->string('cashvan_apk_url')->nullable();
            $table->boolean('is_latest')->default(false);
            $table->text('release_notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
    }
};
