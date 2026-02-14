<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'seller', 'livreur', 'cashvan') DEFAULT 'seller'");
        DB::statement("ALTER TABLE caisses MODIFY COLUMN type ENUM('principale', 'vendeur', 'livreur', 'cashvan')");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE caisses MODIFY COLUMN type ENUM('principale', 'vendeur', 'livreur')");
        DB::statement("ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'manager', 'seller', 'livreur') DEFAULT 'seller'");
    }
};
