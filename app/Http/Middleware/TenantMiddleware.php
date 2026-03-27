<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Services\TenantService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $tenantId = $request->header('X-Tenant-Id');

        if (!$tenantId) {
            return response()->json(['message' => 'X-Tenant-Id header is required.'], 400);
        }

        $tenant = Tenant::find($tenantId);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found.'], 404);
        }

        // Check scheduled deactivation
        if ($tenant->is_active && $tenant->deactivate_at && $tenant->deactivate_at->isPast()) {
            $tenant->update(['is_active' => false, 'deactivate_at' => null]);
        }

        if (!$tenant->is_active) {
            return response()->json(['message' => 'Tenant account is inactive.'], 403);
        }

        TenantService::switchToTenant($tenant);

        // Verify the switch actually worked
        $actualDb = DB::connection()->getDatabaseName();
        $expectedDb = $tenant->database_name;
        Log::info('Tenant switch', [
            'tenant_id' => $tenant->id,
            'expected_db' => $expectedDb,
            'actual_db' => $actualDb,
            'default_connection' => config('database.default'),
            'uri' => $request->getRequestUri(),
        ]);

        if ($actualDb !== $expectedDb) {
            Log::error('TENANT DB MISMATCH!', [
                'tenant_id' => $tenant->id,
                'expected' => $expectedDb,
                'actual' => $actualDb,
            ]);
        }

        $request->attributes->set('tenant', $tenant);

        $response = $next($request);

        $response->headers->set('X-Tenant-Version', $tenant->app_version ?? '1.0');
        $response->headers->set('X-Tenant-Db', $actualDb);

        return $response;
    }
}
