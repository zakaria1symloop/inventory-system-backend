<?php

namespace App\Console\Commands;

use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\TenantEmailMap;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\TenantService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

class TenantCreate extends Command
{
    protected $signature = 'tenant:create {company} {email} {password} {--plan=free}';

    protected $description = 'Create a new tenant with database provisioning';

    public function handle(): int
    {
        $plan = $this->option('plan');
        $limits = Tenant::planLimits();

        if (!isset($limits[$plan])) {
            $this->error("Invalid plan: {$plan}. Available: free, starter, pro, business");
            return self::FAILURE;
        }

        if (TenantEmailMap::where('email', $this->argument('email'))->exists()) {
            $this->error('Email already registered.');
            return self::FAILURE;
        }

        $this->info('Creating tenant...');

        $tenant = Tenant::create([
            'name' => $this->argument('company'),
            'database_name' => 'rb_tenant_temp',
            'plan' => $plan,
            'product_limit' => $limits[$plan]['products'],
            'user_limit' => $limits[$plan]['users'],
            'is_active' => true,
            'trial_ends_at' => now()->addDays(14),
        ]);

        $tenant->database_name = 'rb_tenant_' . $tenant->id;
        $tenant->save();

        TenantEmailMap::create([
            'email' => $this->argument('email'),
            'tenant_id' => $tenant->id,
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => $plan,
            'amount' => Tenant::planPrices()[$plan],
            'status' => 'active',
            'starts_at' => now(),
        ]);

        $this->info("Provisioning database: {$tenant->database_name}");
        TenantService::provision($tenant);

        TenantService::switchToTenant($tenant);

        User::create([
            'name' => $this->argument('company') . ' Admin',
            'email' => $this->argument('email'),
            'password' => Hash::make($this->argument('password')),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Warehouse::create([
            'name' => 'المستودع الرئيسي',
            'is_main' => true,
            'is_active' => true,
        ]);

        Artisan::call('db:seed', [
            '--class' => 'ClientCategorySeeder',
            '--database' => 'tenant',
            '--force' => true,
        ]);

        TenantService::switchToMaster();

        $this->info("Tenant created successfully!");
        $this->table(
            ['ID', 'Company', 'Database', 'Plan', 'Email'],
            [[$tenant->id, $tenant->name, $tenant->database_name, $plan, $this->argument('email')]]
        );

        return self::SUCCESS;
    }
}
