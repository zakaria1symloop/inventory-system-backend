<?php

namespace App\Observers;

use App\Models\TenantEmailMap;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UserObserver
{
    public function created(User $user): void
    {
        $tenantDb = config('database.connections.tenant.database');
        if (!$tenantDb) {
            return;
        }

        // Find tenant_id from the database name
        $tenant = DB::connection('mysql')
            ->table('tenants')
            ->where('database_name', $tenantDb)
            ->first();

        if ($tenant) {
            DB::connection('mysql')->table('tenant_email_map')->updateOrInsert(
                ['email' => $user->email],
                ['tenant_id' => $tenant->id, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function updated(User $user): void
    {
        if (!$user->isDirty('email')) {
            return;
        }

        $tenantDb = config('database.connections.tenant.database');
        if (!$tenantDb) {
            return;
        }

        $tenant = DB::connection('mysql')
            ->table('tenants')
            ->where('database_name', $tenantDb)
            ->first();

        if ($tenant) {
            // Remove old mapping
            DB::connection('mysql')->table('tenant_email_map')
                ->where('email', $user->getOriginal('email'))
                ->where('tenant_id', $tenant->id)
                ->delete();

            // Create new mapping
            DB::connection('mysql')->table('tenant_email_map')->updateOrInsert(
                ['email' => $user->email],
                ['tenant_id' => $tenant->id, 'updated_at' => now(), 'created_at' => now()]
            );
        }
    }

    public function deleted(User $user): void
    {
        $tenantDb = config('database.connections.tenant.database');
        if (!$tenantDb) {
            return;
        }

        $tenant = DB::connection('mysql')
            ->table('tenants')
            ->where('database_name', $tenantDb)
            ->first();

        if ($tenant) {
            DB::connection('mysql')->table('tenant_email_map')
                ->where('email', $user->email)
                ->where('tenant_id', $tenant->id)
                ->delete();
        }
    }
}
