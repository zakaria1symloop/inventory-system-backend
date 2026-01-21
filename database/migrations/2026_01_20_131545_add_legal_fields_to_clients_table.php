<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('rc')->nullable()->after('is_active');
            $table->string('nif')->nullable()->after('rc');
            $table->string('ai')->nullable()->after('nif');
            $table->string('nis')->nullable()->after('ai');
            $table->string('rib')->nullable()->after('nis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['rc', 'nif', 'ai', 'nis', 'rib']);
        });
    }
};
