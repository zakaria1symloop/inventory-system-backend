<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE delivery_returns MODIFY COLUMN reason ENUM('refused', 'damaged', 'excess', 'store_closed', 'wrong', 'other') DEFAULT 'other'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE delivery_returns MODIFY COLUMN reason ENUM('refused', 'damaged', 'excess', 'store_closed', 'other') DEFAULT 'other'");
    }
};
