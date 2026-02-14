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
            if (!Schema::hasColumn('clients', 'rc')) {
                $table->string('rc')->nullable()->after('is_active');
            }
            if (!Schema::hasColumn('clients', 'nif')) {
                $table->string('nif')->nullable()->after('rc');
            }
            if (!Schema::hasColumn('clients', 'ai')) {
                $table->string('ai')->nullable()->after('nif');
            }
            if (!Schema::hasColumn('clients', 'nis')) {
                $table->string('nis')->nullable()->after('ai');
            }
            if (!Schema::hasColumn('clients', 'rib')) {
                $table->string('rib')->nullable()->after('nis');
            }
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
