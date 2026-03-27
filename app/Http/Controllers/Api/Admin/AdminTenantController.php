<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantEmailMap;
use App\Models\TenantUpdateLog;
use App\Models\User;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Api\BackupController;

class AdminTenantController extends Controller
{
    public function index(Request $request)
    {
        $query = Tenant::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        if ($plan = $request->input('plan')) {
            $query->where('plan', $plan);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $tenants = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($tenants);
    }

    public function show($id)
    {
        $tenant = Tenant::with(['activeSubscription', 'subscriptions', 'emailMaps'])->findOrFail($id);

        $prices = Tenant::planPrices();
        $limits = Tenant::planLimits();

        // Get emails linked to this tenant
        $emails = TenantEmailMap::where('tenant_id', $id)->pluck('email');

        // Try to get stats from tenant DB
        $tenantStats = [
            'user_count' => 0,
            'product_count' => 0,
            'client_count' => 0,
            'order_count' => 0,
            'sale_count' => 0,
            'users' => [],
        ];

        try {
            TenantService::switchToTenant($tenant);

            $tenantStats['user_count'] = DB::table('users')->count();
            $tenantStats['product_count'] = DB::table('products')->count();
            $tenantStats['client_count'] = DB::table('clients')->count();

            if (DB::getSchemaBuilder()->hasTable('orders')) {
                $tenantStats['order_count'] = DB::table('orders')->count();
            }
            if (DB::getSchemaBuilder()->hasTable('sales')) {
                $tenantStats['sale_count'] = DB::table('sales')->count();
            }

            $tenantStats['users'] = DB::table('users')
                ->select('id', 'name', 'email', 'role', 'is_active', 'email_verified_at', 'created_at')
                ->get();

            TenantService::switchToMaster();
        } catch (\Exception $e) {
            TenantService::switchToMaster();
        }

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'database_name' => $tenant->database_name,
            'plan' => $tenant->plan,
            'product_limit' => $tenant->product_limit,
            'is_active' => $tenant->is_active,
            'otp_required' => $tenant->otp_required,
            'deactivate_at' => $tenant->deactivate_at,
            'updates_enabled' => $tenant->updates_enabled,
            'app_version' => $tenant->app_version ?? '1.0',
            'latest_version' => config('app.tenant_version', '1.0'),
            'last_updated_at' => $tenant->last_updated_at,
            'trial_ends_at' => $tenant->trial_ends_at,
            'created_at' => $tenant->created_at,
            'updated_at' => $tenant->updated_at,
            'current_price' => $prices[$tenant->plan] ?? 0,
            'emails' => $emails,
            'active_subscription' => $tenant->activeSubscription,
            'subscriptions' => $tenant->subscriptions,
            'stats' => $tenantStats,
            'user_limit' => $tenant->user_limit,
            'plans_info' => collect($limits)->map(fn($limit, $key) => [
                'id' => $key,
                'product_limit' => $limit['products'],
                'user_limit' => $limit['users'],
                'price' => $prices[$key] ?? 0,
                'extra_user_price' => Tenant::extraUserPrice()[$key] ?? 0,
            ]),
        ]);
    }

    public function updatePlan(Request $request, $id)
    {
        $request->validate([
            'plan' => 'required|in:free,starter,pro,business',
        ]);

        $tenant = Tenant::findOrFail($id);
        $limits = Tenant::planLimits();

        $tenant->update([
            'plan' => $request->plan,
            'product_limit' => $limits[$request->plan]['products'],
            'user_limit' => $limits[$request->plan]['users'],
        ]);

        return response()->json([
            'message' => 'تم تحديث الخطة بنجاح.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function toggleActive($id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['is_active' => !$tenant->is_active, 'deactivate_at' => null]);

        return response()->json([
            'message' => $tenant->is_active ? 'تم تفعيل المستأجر.' : 'تم تعطيل المستأجر.',
            'tenant' => $tenant,
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'action' => 'required|in:activate,deactivate,schedule',
            'deactivate_at' => 'required_if:action,schedule|date|after:now',
        ]);

        $tenant = Tenant::findOrFail($id);

        switch ($request->action) {
            case 'activate':
                $tenant->update(['is_active' => true, 'deactivate_at' => null]);
                return response()->json([
                    'message' => 'تم تفعيل المستأجر.',
                    'tenant' => $tenant->fresh(),
                ]);

            case 'deactivate':
                $tenant->update(['is_active' => false, 'deactivate_at' => null]);
                return response()->json([
                    'message' => 'تم تعطيل المستأجر فوراً.',
                    'tenant' => $tenant->fresh(),
                ]);

            case 'schedule':
                $date = \Carbon\Carbon::parse($request->deactivate_at);
                $tenant->update(['deactivate_at' => $date, 'is_active' => true]);
                return response()->json([
                    'message' => 'تم جدولة التعطيل في ' . $date->format('Y-m-d') . '.',
                    'tenant' => $tenant->fresh(),
                ]);
        }
    }

    public function productCount($id)
    {
        $tenant = Tenant::findOrFail($id);

        try {
            TenantService::switchToTenant($tenant);
            $count = DB::table('products')->count();
            TenantService::switchToMaster();

            return response()->json([
                'product_count' => $count,
                'product_limit' => $tenant->product_limit,
            ]);
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            return response()->json([
                'product_count' => 0,
                'product_limit' => $tenant->product_limit,
                'error' => 'لا يمكن الوصول لقاعدة بيانات المستأجر.',
            ]);
        }
    }

    public function grantFullAccess(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $tenant->update([
            'plan' => 'business',
            'product_limit' => 999999,
            'user_limit' => 999999,
            'is_active' => true,
            'trial_ends_at' => now()->addYears(10),
        ]);

        return response()->json([
            'message' => 'تم منح الوصول الكامل بنجاح. الخطة: الأعمال، بدون حد منتجات، صالح لمدة 10 سنوات.',
            'tenant' => $tenant->fresh(),
        ]);
    }

    public function toggleUpdates($id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['updates_enabled' => !$tenant->updates_enabled]);

        return response()->json([
            'message' => $tenant->updates_enabled ? 'تم تفعيل التحديثات لهذا المستأجر.' : 'تم تعطيل التحديثات لهذا المستأجر.',
            'tenant' => $tenant,
        ]);
    }

    public function pushUpdate($id)
    {
        $tenant = Tenant::findOrFail($id);
        $latestVersion = config('app.tenant_version', '1.0');
        $fromVersion = $tenant->app_version ?? '1.0';

        if ($fromVersion === $latestVersion) {
            return response()->json(['message' => 'المستأجر محدث بالفعل إلى الإصدار ' . $latestVersion], 200);
        }

        try {
            TenantService::switchToTenant($tenant);

            $beforeMigrations = DB::table('migrations')->pluck('migration')->toArray();

            Artisan::call('migrate', [
                '--database' => 'tenant',
                '--path' => 'database/migrations',
                '--force' => true,
            ]);

            $afterMigrations = DB::table('migrations')->pluck('migration')->toArray();
            $newMigrations = array_values(array_diff($afterMigrations, $beforeMigrations));

            TenantService::switchToMaster();

            $tenant->update([
                'app_version' => $latestVersion,
                'last_updated_at' => now(),
            ]);

            TenantUpdateLog::create([
                'tenant_id' => $tenant->id,
                'from_version' => $fromVersion,
                'to_version' => $latestVersion,
                'status' => 'success',
                'migrations_run' => $newMigrations,
            ]);

            return response()->json([
                'message' => "تم تحديث المستأجر بنجاح من v{$fromVersion} إلى v{$latestVersion}. تم تشغيل " . count($newMigrations) . " migration.",
                'tenant' => $tenant->fresh(),
                'migrations_run' => $newMigrations,
            ]);

        } catch (\Exception $e) {
            TenantService::switchToMaster();

            TenantUpdateLog::create([
                'tenant_id' => $tenant->id,
                'from_version' => $fromVersion,
                'to_version' => $latestVersion,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'فشل التحديث: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function pushBulkUpdate()
    {
        $latestVersion = config('app.tenant_version', '1.0');

        $tenants = Tenant::where('is_active', true)
            ->where('updates_enabled', true)
            ->where(function ($q) use ($latestVersion) {
                $q->where('app_version', '<', $latestVersion)
                  ->orWhereNull('app_version');
            })
            ->get();

        if ($tenants->isEmpty()) {
            return response()->json([
                'message' => 'جميع المستأجرين محدثين بالفعل.',
                'results' => [],
            ]);
        }

        $results = [];

        foreach ($tenants as $tenant) {
            $fromVersion = $tenant->app_version ?? '1.0';

            try {
                TenantService::switchToTenant($tenant);

                $beforeMigrations = DB::table('migrations')->pluck('migration')->toArray();

                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations',
                    '--force' => true,
                ]);

                $afterMigrations = DB::table('migrations')->pluck('migration')->toArray();
                $newMigrations = array_values(array_diff($afterMigrations, $beforeMigrations));

                TenantService::switchToMaster();

                $tenant->update([
                    'app_version' => $latestVersion,
                    'last_updated_at' => now(),
                ]);

                TenantUpdateLog::create([
                    'tenant_id' => $tenant->id,
                    'from_version' => $fromVersion,
                    'to_version' => $latestVersion,
                    'status' => 'success',
                    'migrations_run' => $newMigrations,
                ]);

                $results[] = [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->name,
                    'from' => $fromVersion,
                    'to' => $latestVersion,
                    'status' => 'success',
                    'migrations_count' => count($newMigrations),
                ];

            } catch (\Exception $e) {
                TenantService::switchToMaster();

                TenantUpdateLog::create([
                    'tenant_id' => $tenant->id,
                    'from_version' => $fromVersion,
                    'to_version' => $latestVersion,
                    'status' => 'failed',
                    'error_message' => $e->getMessage(),
                ]);

                $results[] = [
                    'tenant_id' => $tenant->id,
                    'name' => $tenant->name,
                    'from' => $fromVersion,
                    'to' => $latestVersion,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ];
            }
        }

        TenantService::switchToMaster();

        $successCount = collect($results)->where('status', 'success')->count();
        $failCount = collect($results)->where('status', 'failed')->count();

        return response()->json([
            'message' => "تم تحديث {$successCount} مستأجر بنجاح. فشل {$failCount}.",
            'results' => $results,
        ]);
    }

    public function getUpdateLogs($id)
    {
        $tenant = Tenant::findOrFail($id);

        $logs = TenantUpdateLog::where('tenant_id', $id)
            ->latest()
            ->limit(50)
            ->get();

        return response()->json([
            'tenant_id' => $tenant->id,
            'current_version' => $tenant->app_version ?? '1.0',
            'latest_version' => config('app.tenant_version', '1.0'),
            'logs' => $logs,
        ]);
    }

    public function createUser(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
            'password' => 'required|min:6',
            'role' => 'required|in:admin,manager,seller,livreur,cashvan',
            'force' => 'boolean',
        ]);

        $tenant = Tenant::findOrFail($id);

        try {
            TenantService::switchToTenant($tenant);

            // Check if email already exists in tenant DB
            if (User::where('email', $request->email)->exists()) {
                TenantService::switchToMaster();
                return response()->json(['message' => 'البريد الإلكتروني مستخدم بالفعل في هذا المستأجر.'], 422);
            }

            // Check user limit (admin can force override)
            if (!$request->boolean('force') && $tenant->user_limit) {
                $currentCount = User::count();
                if ($currentCount >= $tenant->user_limit) {
                    TenantService::switchToMaster();
                    return response()->json([
                        'message' => 'لقد وصل المستأجر للحد الأقصى من المستخدمين (' . $tenant->user_limit . '). يمكنك الإنشاء بالقوة.',
                        'limit' => $tenant->user_limit,
                        'current' => $currentCount,
                    ], 403);
                }
            }

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => \Illuminate\Support\Facades\Hash::make($request->password),
                'role' => $request->role,
                'is_active' => true,
            ]);

            TenantService::switchToMaster();

            return response()->json([
                'message' => 'تم إنشاء المستخدم بنجاح.',
                'user' => $user,
            ], 201);
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            return response()->json(['message' => 'خطأ في إنشاء المستخدم: ' . $e->getMessage()], 500);
        }
    }

    public function getFeatures($id)
    {
        $tenant = Tenant::findOrFail($id);
        $allModules = config('features.modules', []);
        $enabledFeatures = $tenant->getEnabledFeatures();
        $planDefaults = Tenant::getDefaultFeatures($tenant->plan);

        // Group modules
        $grouped = [];
        foreach ($allModules as $key => $meta) {
            $group = $meta['group'] ?? 'أخرى';
            if (!isset($grouped[$group])) {
                $grouped[$group] = [];
            }
            $grouped[$group][] = [
                'key' => $key,
                'name' => $meta['name'],
                'enabled' => in_array($key, $enabledFeatures),
                'is_plan_default' => in_array($key, $planDefaults),
            ];
        }

        return response()->json([
            'tenant_id' => $tenant->id,
            'plan' => $tenant->plan,
            'is_custom' => !empty($tenant->features),
            'enabled_features' => $enabledFeatures,
            'plan_defaults' => $planDefaults,
            'grouped_modules' => $grouped,
        ]);
    }

    public function updateFeatures(Request $request, $id)
    {
        $request->validate([
            'features' => 'required|array',
            'features.*' => 'string',
        ]);

        $tenant = Tenant::findOrFail($id);
        $allKeys = array_keys(config('features.modules', []));
        $validFeatures = array_values(array_intersect($request->features, $allKeys));

        $tenant->update(['features' => $validFeatures]);

        return response()->json([
            'message' => 'تم تحديث الميزات بنجاح.',
            'features' => $validFeatures,
        ]);
    }

    public function resetFeatures($id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['features' => null]);
        $defaults = Tenant::getDefaultFeatures($tenant->plan);

        return response()->json([
            'message' => 'تم إعادة تعيين الميزات إلى الإعدادات الافتراضية للخطة.',
            'features' => $defaults,
        ]);
    }

    public function impersonate($id)
    {
        $tenant = Tenant::findOrFail($id);

        if (!$tenant->is_active) {
            return response()->json(['message' => 'المستأجر غير مفعل.'], 403);
        }

        try {
            TenantService::switchToTenant($tenant);

            $user = User::where('role', 'admin')->first();

            if (!$user) {
                TenantService::switchToMaster();
                return response()->json(['message' => 'لا يوجد مستخدم أدمن لهذا المستأجر.'], 404);
            }

            $token = $user->createToken('impersonate-token')->plainTextToken;

            TenantService::switchToMaster();

            return response()->json([
                'token' => $token,
                'tenant_id' => $tenant->id,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            return response()->json(['message' => 'خطأ في الدخول للوحة التحكم: ' . $e->getMessage()], 500);
        }
    }

    public function toggleUserVerification($id, $userId)
    {
        $tenant = Tenant::findOrFail($id);

        try {
            TenantService::switchToTenant($tenant);

            $user = User::findOrFail($userId);
            $user->update([
                'email_verified_at' => $user->email_verified_at ? null : now(),
            ]);

            $verified = (bool) $user->email_verified_at;

            TenantService::switchToMaster();

            return response()->json([
                'message' => $verified ? 'تم تفعيل التحقق من البريد.' : 'تم إلغاء التحقق من البريد.',
                'email_verified_at' => $user->email_verified_at,
            ]);
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            return response()->json(['message' => 'خطأ: ' . $e->getMessage()], 500);
        }
    }

    public function toggleOtpRequired($id)
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['otp_required' => !$tenant->otp_required]);

        return response()->json([
            'message' => $tenant->otp_required ? 'تم تفعيل التحقق بالبريد الإلكتروني.' : 'تم تعطيل التحقق بالبريد الإلكتروني.',
            'otp_required' => $tenant->otp_required,
        ]);
    }

    public function destroy($id)
    {
        $tenant = Tenant::findOrFail($id);
        $dbName = $tenant->database_name;

        // Delete related records
        $tenant->emailMaps()->delete();
        $tenant->subscriptions()->delete();
        $tenant->updateLogs()->delete();

        // Drop tenant database
        try {
            DB::statement("DROP DATABASE IF EXISTS `{$dbName}`");
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'فشل حذف قاعدة البيانات: ' . $e->getMessage(),
            ], 500);
        }

        $tenant->delete();

        return response()->json([
            'message' => 'تم حذف المستأجر وقاعدة بياناته بنجاح.',
        ]);
    }

    public function debugDbState()
    {
        $masterDb = config('database.connections.mysql.database');
        $results = [
            'master_db' => $masterDb,
            'default_connection' => config('database.default'),
        ];

        // Check if business tables exist in master DB
        try {
            $masterTables = DB::connection('mysql')->select("SHOW TABLES LIKE 'clients'");
            $results['master_has_clients_table'] = count($masterTables) > 0;
            if (count($masterTables) > 0) {
                $results['master_clients_count'] = DB::connection('mysql')->table('clients')->count();
            }
        } catch (\Exception $e) {
            $results['master_check_error'] = $e->getMessage();
        }

        // Check each tenant
        $tenants = Tenant::all();
        $results['tenants'] = [];

        foreach ($tenants as $tenant) {
            $tenantInfo = [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'database_name' => $tenant->database_name,
            ];

            try {
                TenantService::switchToTenant($tenant);

                // Verify actual DB
                $actualDb = DB::select('SELECT DATABASE() as db')[0]->db;
                $tenantInfo['actual_db'] = $actualDb;
                $tenantInfo['default_conn'] = config('database.default');
                $tenantInfo['match'] = $actualDb === $tenant->database_name;

                // Count clients
                $tenantInfo['clients_count'] = DB::table('clients')->count();
                $tenantInfo['users_count'] = DB::table('users')->count();

                TenantService::switchToMaster();
            } catch (\Exception $e) {
                TenantService::switchToMaster();
                $tenantInfo['error'] = $e->getMessage();
            }

            $results['tenants'][] = $tenantInfo;
        }

        return response()->json($results);
    }

    public function migrateAll()
    {
        $tenants = Tenant::where('is_active', true)->get();
        $results = [];

        foreach ($tenants as $tenant) {
            try {
                TenantService::switchToTenant($tenant);

                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => 'database/migrations',
                    '--force' => true,
                ]);

                TenantService::switchToMaster();
                $results[] = ['id' => $tenant->id, 'name' => $tenant->name, 'status' => 'success'];
            } catch (\Exception $e) {
                TenantService::switchToMaster();
                $results[] = ['id' => $tenant->id, 'name' => $tenant->name, 'status' => 'failed', 'error' => $e->getMessage()];
            }
        }

        return response()->json(['message' => 'Migration complete', 'results' => $results]);
    }

    public function exportSql($id)
    {
        $tenant = Tenant::findOrFail($id);

        set_time_limit(300);

        try {
            TenantService::switchToTenant($tenant);

            $backupController = new BackupController();
            $sql = $backupController->generateSqlDump();

            TenantService::switchToMaster();
        } catch (\Exception $e) {
            TenantService::switchToMaster();
            return response()->json([
                'message' => 'فشل تصدير قاعدة البيانات: ' . $e->getMessage(),
            ], 500);
        }

        $filename = $tenant->name . '_' . now()->format('Y-m-d_His') . '.sql';

        return response($sql)
            ->header('Content-Type', 'application/sql')
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }
}
