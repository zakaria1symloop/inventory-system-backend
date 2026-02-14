<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('client_category_id')->constrained('users')->nullOnDelete();
            $table->string('source', 20)->default('web')->after('created_by');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->string('source', 20)->default('web')->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['created_by', 'source']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
