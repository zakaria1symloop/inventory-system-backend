<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('device_id');
            $table->string('entity_type');
            $table->unsignedBigInteger('entity_id');
            $table->enum('action', ['create', 'update', 'delete']);
            $table->json('data')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->enum('status', ['pending', 'synced', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id', 'device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_logs');
    }
};
