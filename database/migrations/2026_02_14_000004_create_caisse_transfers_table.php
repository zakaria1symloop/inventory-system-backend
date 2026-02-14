<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caisse_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_caisse_id')->constrained('caisses')->onDelete('cascade');
            $table->foreignId('to_caisse_id')->constrained('caisses')->onDelete('cascade');
            $table->decimal('amount', 12, 2);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caisse_transfers');
    }
};
