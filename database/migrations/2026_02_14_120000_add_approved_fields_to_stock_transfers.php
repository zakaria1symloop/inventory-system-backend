<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->unsignedBigInteger('approved_by')->nullable()->after('collected_by');
            $table->timestamp('approved_at')->nullable()->after('collected_at');

            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });

        // Update existing 'collected' records that were approved implicitly
        DB::table('stock_transfers')
            ->where('status', 'collected')
            ->whereNull('approved_by')
            ->update([
                'approved_by' => DB::raw('collected_by'),
                'approved_at' => DB::raw('collected_at'),
            ]);
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropForeign(['approved_by']);
            $table->dropColumn(['approved_by', 'approved_at']);
        });
    }
};
