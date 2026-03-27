<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TenantEmailMap;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class TenantService
{
    public static function switchToTenant(Tenant $tenant): void
    {
        $dbName = $tenant->database_name;

        // Set tenant database name on the tenant connection
        config(['database.connections.tenant.database' => $dbName]);

        // Purge old connection and reconnect with new database
        DB::purge('tenant');
        DB::reconnect('tenant');

        // Set tenant as default connection
        DB::setDefaultConnection('tenant');
    }

    public static function switchToMaster(): void
    {
        DB::setDefaultConnection('mysql');
    }

    public static function provision(Tenant $tenant): void
    {
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$tenant->database_name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        config(['database.connections.tenant.database' => $tenant->database_name]);
        DB::purge('tenant');
        DB::reconnect('tenant');

        Artisan::call('migrate', [
            '--database' => 'tenant',
            '--path' => 'database/migrations',
            '--force' => true,
        ]);
    }

    public static function findByEmail(string $email): ?Tenant
    {
        $map = TenantEmailMap::where('email', $email)->first();

        return $map ? $map->tenant : null;
    }

    public static function findByPhone(string $phone): ?Tenant
    {
        $map = TenantEmailMap::where('phone', $phone)->first();

        return $map ? $map->tenant : null;
    }

    public static function findByIdentifier(string $identifier): ?Tenant
    {
        // If it looks like an email, search by email; otherwise by phone
        if (filter_var($identifier, FILTER_VALIDATE_EMAIL)) {
            return static::findByEmail($identifier);
        }

        return static::findByPhone($identifier);
    }
}
