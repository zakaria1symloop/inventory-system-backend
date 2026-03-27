<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\TenantUpdateLog;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class TenantMigrate extends Command
{
    protected $signature = 'tenant:migrate
        {--tenant= : Specific tenant ID to migrate}
        {--updates-only : Only migrate tenants with updates_enabled = true}';

    protected $description = 'Run migrations on tenant databases and bump their app_version';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        $updatesOnly = $this->option('updates-only');
        $latestVersion = config('app.tenant_version', '1.0');

        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->get();
            if ($tenants->isEmpty()) {
                $this->error("Tenant #{$tenantId} not found.");
                return self::FAILURE;
            }
        } else {
            $query = Tenant::where('is_active', true);
            if ($updatesOnly) {
                $query->where('updates_enabled', true);
            }
            $tenants = $query->get();
        }

        if ($tenants->isEmpty()) {
            $this->info('No tenants found.');
            return self::SUCCESS;
        }

        $this->info("Migrating {$tenants->count()} tenant(s) → version {$latestVersion}");
        $this->newLine();

        $results = [];

        foreach ($tenants as $tenant) {
            $fromVersion = $tenant->app_version ?? '1.0';
            $this->info("▸ {$tenant->name} (#{$tenant->id}) — v{$fromVersion}");

            try {
                TenantService::switchToTenant($tenant);

                // Get migrations before running
                $beforeMigrations = DB::table('migrations')->pluck('migration')->toArray();

                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations',
                    '--force' => true,
                ]);

                // Get migrations after running
                $afterMigrations = DB::table('migrations')->pluck('migration')->toArray();
                $newMigrations = array_values(array_diff($afterMigrations, $beforeMigrations));

                TenantService::switchToMaster();

                // Bump version
                $tenant->update([
                    'app_version' => $latestVersion,
                    'last_updated_at' => now(),
                ]);

                // Log success
                TenantUpdateLog::create([
                    'tenant_id' => $tenant->id,
                    'from_version' => $fromVersion,
                    'to_version' => $latestVersion,
                    'status' => 'success',
                    'migrations_run' => $newMigrations,
                ]);

                $migrationCount = count($newMigrations);
                $this->info("  ✓ Done — {$migrationCount} migration(s) run → v{$latestVersion}");

                $results[] = [$tenant->id, $tenant->name, $fromVersion, $latestVersion, $migrationCount, '✓'];

            } catch (\Exception $e) {
                TenantService::switchToMaster();

                // Log failure
                TenantUpdateLog::create([
                    'tenant_id' => $tenant->id,
                    'from_version' => $fromVersion,
                    'to_version' => $latestVersion,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                $this->error("  ✗ Failed: {$e->getMessage()}");

                $results[] = [$tenant->id, $tenant->name, $fromVersion, '—', 0, '✗'];
            }

            $this->newLine();
        }

        TenantService::switchToMaster();

        // Summary table
        $this->table(
            ['ID', 'Name', 'From', 'To', 'Migrations', 'Status'],
            $results
        );

        return self::SUCCESS;
    }
}
