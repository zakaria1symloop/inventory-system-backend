<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SaasPayment;
use Illuminate\Http\Request;

class AdminPaymentController extends Controller
{
    public function index(Request $request)
    {
        $query = SaasPayment::with('tenant');

        if ($search = $request->input('search')) {
            $query->whereHas('tenant', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('id', $search);
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        if ($from = $request->input('from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->input('to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        $payments = $query->latest()->paginate($request->input('per_page', 15));

        // Summary stats
        $totalPaid = SaasPayment::where('status', 'paid')->sum('amount');
        $thisMonth = SaasPayment::where('status', 'paid')
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');
        $pendingCount = SaasPayment::where('status', 'pending')->count();

        return response()->json([
            'payments' => $payments,
            'summary' => [
                'total_paid' => $totalPaid,
                'this_month' => $thisMonth,
                'pending_count' => $pendingCount,
            ],
        ]);
    }
}
