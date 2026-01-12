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
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('has_problem')->default(false)->after('notes');
            $table->text('problem_description')->nullable()->after('has_problem');
            $table->timestamp('problem_reported_at')->nullable()->after('problem_description');
            $table->foreignId('problem_reported_by')->nullable()->constrained('users')->nullOnDelete()->after('problem_reported_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['problem_reported_by']);
            $table->dropColumn(['has_problem', 'problem_description', 'problem_reported_at', 'problem_reported_by']);
        });
    }
};
