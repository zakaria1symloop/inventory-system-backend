<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Tenant;

class AdminDashboardController extends Controller
{
    public function index()
    {
        $totalTenants = Tenant::count();
        $activeTenants = Tenant::where('is_active', true)->count();
        $inactiveTenants = Tenant::where('is_active', false)->count();

        $tenantsByPlan = Tenant::selectRaw('plan, count(*) as count')
            ->groupBy('plan')
            ->pluck('count', 'plan');

        $prices = Tenant::planPrices();
        $revenueEstimate = 0;
        foreach ($tenantsByPlan as $plan => $count) {
            $revenueEstimate += ($prices[$plan] ?? 0) * $count;
        }

        $unreadMessages = ContactMessage::where('is_read', false)->count();

        $recentTenants = Tenant::latest()
            ->take(5)
            ->get(['id', 'name', 'plan', 'is_active', 'created_at']);

        $latestVersion = config('app.tenant_version', '1.0');
        $pendingUpdates = Tenant::where('is_active', true)
            ->where('updates_enabled', true)
            ->where(function ($q) use ($latestVersion) {
                $q->where('app_version', '<', $latestVersion)
                  ->orWhereNull('app_version');
            })
            ->count();

        return response()->json([
            'total_tenants' => $totalTenants,
            'active_tenants' => $activeTenants,
            'inactive_tenants' => $inactiveTenants,
            'tenants_by_plan' => $tenantsByPlan,
            'revenue_estimate' => $revenueEstimate,
            'unread_messages' => $unreadMessages,
            'recent_tenants' => $recentTenants,
            'latest_version' => $latestVersion,
            'pending_updates' => $pendingUpdates,
        ]);
    }
}
