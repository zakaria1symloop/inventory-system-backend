<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Change enum to include 'loading' status
        DB::statement("ALTER TABLE stock_transfers MODIFY COLUMN status ENUM('pending', 'loading', 'collected') NOT NULL DEFAULT 'pending'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE stock_transfers MODIFY COLUMN status ENUM('pending', 'collected') NOT NULL DEFAULT 'pending'");
    }
};
