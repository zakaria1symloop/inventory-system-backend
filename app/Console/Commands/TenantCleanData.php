<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TenantCleanData extends Command
{
    protected $signature = 'tenant:clean-data {--tenant= : Specific tenant ID}';
    protected $description = 'Clean all tenant data except users and products (and their dependencies)';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');

        if ($tenantId) {
            $tenants = Tenant::where('id', $tenantId)->get();
        } else {
            $tenants = Tenant::where('is_active', true)->get();
        }

        // Tables to KEEP (users, products, and their FK dependencies + system tables)
        $keepTables = [
            'users',
            'products',
            'categories',
            'brands',
            'units',
            'warehouses',
            'client_categories',
            'product_category_prices',
            'settings',
            'migrations',
            'cache',
            'cache_locks',
            'sessions',
            'personal_access_tokens',
            'password_reset_tokens',
            'failed_jobs',
            'jobs',
            'job_batches',
        ];

        // Tables to TRUNCATE (all transactional/operational data)
        $truncateTables = [
            'sale_return_items',
            'sale_returns',
            'sale_items',
            'sales',
            'van_sale_items',
            'van_sales',
            'van_returns',
            'van_session_items',
            'van_sessions',
            'purchase_return_items',
            'purchase_returns',
            'purchase_items',
            'purchases',
            'purchase_order_items',
            'purchase_orders',
            'product_request_items',
            'product_requests',
            'delivery_returns',
            'delivery_stock',
            'delivery_orders',
            'deliveries',
            'order_items',
            'orders',
            'trip_stores',
            'trips',
            'adjustment_items',
            'adjustments',
            'stock_transfer_items',
            'stock_transfers',
            'stock_movements',
            'stock',
            'payments',
            'caisse_settlements',
            'caisse_transfers',
            'caisse_transactions',
            'caisses',
            'dispenses',
            'employees',
            'clients',
            'suppliers',
            'vehicles',
            'sync_logs',
        ];

        foreach ($tenants as $tenant) {
            $this->info("Cleaning tenant: {$tenant->name} (#{$tenant->id})");

            try {
                TenantService::switchToTenant($tenant);

                DB::statement('SET FOREIGN_KEY_CHECKS=0');

                foreach ($truncateTables as $table) {
                    try {
                        DB::table($table)->truncate();
                        $this->line("  Truncated: {$table}");
                    } catch (\Exception $e) {
                        $this->warn("  Skipped {$table}: {$e->getMessage()}");
                    }
                }

                DB::statement('SET FOREIGN_KEY_CHECKS=1');

                TenantService::switchToMaster();
                $this->info("  ✓ Done");
                $this->newLine();

            } catch (\Exception $e) {
                TenantService::switchToMaster();
                $this->error("  ✗ Failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
