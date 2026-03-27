<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('name');
        });

        // Backfill existing clients with CLT-{id padded to 4}
        DB::table('clients')->orderBy('id')->each(function ($client) {
            DB::table('clients')
                ->where('id', $client->id)
                ->update(['code' => 'CLT-' . str_pad($client->id, 4, '0', STR_PAD_LEFT)]);
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
