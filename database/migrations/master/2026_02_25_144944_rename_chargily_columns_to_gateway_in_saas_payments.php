<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        Schema::connection('mysql')->table('saas_payments', function (Blueprint $table) {
            $table->renameColumn('chargily_checkout_id', 'gateway_invoice_id');
            $table->renameColumn('chargily_checkout_url', 'gateway_checkout_url');
            $table->renameColumn('chargily_response', 'gateway_response');
        });
    }

    public function down(): void
    {
        Schema::connection('mysql')->table('saas_payments', function (Blueprint $table) {
            $table->renameColumn('gateway_invoice_id', 'chargily_checkout_id');
            $table->renameColumn('gateway_checkout_url', 'chargily_checkout_url');
            $table->renameColumn('gateway_response', 'chargily_response');
        });
    }
};
