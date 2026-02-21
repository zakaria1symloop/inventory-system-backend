<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\CaisseSettlement;
use App\Models\CaisseTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaisseController extends Controller
{
    /**
     * List all caisses (admin sees all, seller/livreur sees own)
     */
    public function index(Request $request)
    {
        $user = auth()->user();

        $query = Caisse::with(['user:id,name,role,phone']);

        if (!$user->isAdmin() && !$user->isManager()) {
            $query->where('user_id', $user->id);
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $caisses = $query->latest()->get();

        return response()->json($caisses);
    }

    /**
     * Create a new caisse for a user
     */
    public function store(Request $request)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'nullable|string|max:255',
        ]);

        $targetUser = \App\Models\User::findOrFail($request->user_id);

        // Only admin can have multiple caisses
        if ($targetUser->role !== 'admin' && Caisse::where('user_id', $targetUser->id)->exists()) {
            return response()->json(['message' => 'هذا المستخدم لديه صندوق بالفعل'], 422);
        }

        // Determine type from role
        $typeMap = [
            'seller' => 'vendeur',
            'livreur' => 'livreur',
            'cashvan' => 'cashvan',
            'admin' => 'principale',
            'manager' => 'principale',
        ];

        $type = $typeMap[$targetUser->role] ?? 'vendeur';

        $caisse = Caisse::create([
            'user_id' => $targetUser->id,
            'type' => $type,
            'name' => $request->name,
        ]);

        return response()->json($caisse->load('user:id,name,role'), 201);
    }

    /**
     * Update caisse name
     */
    public function update(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $caisse = Caisse::findOrFail($id);

        $request->validate([
            'name' => 'nullable|string|max:255',
        ]);

        $caisse->update($request->only('name'));

        return response()->json($caisse->load('user:id,name,role'));
    }

    /**
     * Show caisse details with recent transactions
     */
    public function show($id)
    {
        $user = auth()->user();

        $caisse = Caisse::with(['user:id,name,role,phone'])->findOrFail($id);

        if (!$user->isAdmin() && !$user->isManager() && $caisse->user_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $recentTransactions = $caisse->transactions()
            ->with('creator:id,name')
            ->latest()
            ->take(20)
            ->get();

        $recentSettlements = $caisse->settlements()
            ->with('settler:id,name')
            ->latest()
            ->take(10)
            ->get();

        return response()->json([
            'caisse' => $caisse,
            'recent_transactions' => $recentTransactions,
            'recent_settlements' => $recentSettlements,
        ]);
    }

    /**
     * Paginated transaction history with filters
     */
    public function transactions(Request $request, $id)
    {
        $user = auth()->user();
        $caisse = Caisse::findOrFail($id);

        if (!$user->isAdmin() && !$user->isManager() && $caisse->user_id !== $user->id) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $query = $caisse->transactions()->with('creator:id,name');

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->source_type) {
            $query->where('source_type', $request->source_type);
        }

        if ($request->created_by) {
            $query->where('created_by', $request->created_by);
        }

        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $transactions = $query->latest()->paginate($request->per_page ?? 20);

        // Include totals for current filters
        $totalsQuery = $caisse->transactions();
        if ($request->type) $totalsQuery->where('type', $request->type);
        if ($request->source_type) $totalsQuery->where('source_type', $request->source_type);
        if ($request->created_by) $totalsQuery->where('created_by', $request->created_by);
        if ($request->from_date) $totalsQuery->whereDate('created_at', '>=', $request->from_date);
        if ($request->to_date) $totalsQuery->whereDate('created_at', '<=', $request->to_date);

        $allFiltered = $totalsQuery->get();
        $totalIn = $allFiltered->where('type', 'in')->sum('amount');
        $totalOut = $allFiltered->where('type', 'out')->sum('amount');

        $response = $transactions->toArray();
        $response['totals'] = [
            'total_in' => $totalIn,
            'total_out' => $totalOut,
            'net' => $totalIn - $totalOut,
        ];

        return response()->json($response);
    }

    /**
     * Admin-only: settle a caisse (collect or record deposit)
     */
    public function settle(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'فقط المدير يمكنه إجراء التحصيل'], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:admin_collect,seller_deposit',
            'notes' => 'nullable|string',
        ]);

        $caisse = Caisse::findOrFail($id);

        if ($request->amount > $caisse->balance) {
            return response()->json(['message' => 'المبلغ أكبر من الرصيد الحالي'], 422);
        }

        DB::beginTransaction();

        try {
            $balanceBefore = $caisse->balance;

            // Create settlement record
            $settlement = CaisseSettlement::create([
                'caisse_id' => $caisse->id,
                'amount' => $request->amount,
                'type' => $request->type,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceBefore - $request->amount,
                'notes' => $request->notes,
                'settled_by' => $user->id,
            ]);

            // Create caisse transaction
            $caisse->addTransaction(
                'out',
                $request->amount,
                'settlement',
                $settlement->id,
                $request->type === 'admin_collect' ? 'تحصيل إداري' : 'إيداع بائع',
                $user->id
            );

            DB::commit();

            return response()->json([
                'message' => 'تمت عملية التحصيل بنجاح',
                'settlement' => $settlement->load('settler:id,name'),
                'caisse' => $caisse->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Current user's caisse with balance and recent transactions
     */
    public function myCaisse(Request $request)
    {
        $user = auth()->user();

        $caisse = Caisse::with('user:id,name,role')
            ->where('user_id', $user->id)
            ->first();

        if (!$caisse) {
            return response()->json(['message' => 'لا يوجد صندوق لهذا المستخدم'], 404);
        }

        $recentTransactions = $caisse->transactions()
            ->with('creator:id,name')
            ->latest()
            ->take(20)
            ->get();

        return response()->json([
            'caisse' => $caisse,
            'recent_transactions' => $recentTransactions,
        ]);
    }

    /**
     * Admin/Manager: transfer money between caisses
     */
    public function transfer(Request $request)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'فقط المدير يمكنه إجراء التحويل'], 403);
        }

        $request->validate([
            'from_caisse_id' => 'required|exists:caisses,id',
            'to_caisse_id' => 'required|exists:caisses,id|different:from_caisse_id',
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
        ]);

        $fromCaisse = Caisse::findOrFail($request->from_caisse_id);
        $toCaisse = Caisse::findOrFail($request->to_caisse_id);

        if ($request->amount > $fromCaisse->balance) {
            return response()->json(['message' => 'المبلغ أكبر من رصيد الصندوق المصدر'], 422);
        }

        DB::beginTransaction();

        try {
            $transfer = CaisseTransfer::create([
                'from_caisse_id' => $fromCaisse->id,
                'to_caisse_id' => $toCaisse->id,
                'amount' => $request->amount,
                'notes' => $request->notes,
                'created_by' => $user->id,
            ]);

            $fromCaisse->addTransaction(
                'out',
                $request->amount,
                'transfer',
                $transfer->id,
                'تحويل إلى صندوق ' . ($toCaisse->user->name ?? $toCaisse->id),
                $user->id
            );

            $toCaisse->addTransaction(
                'in',
                $request->amount,
                'transfer',
                $transfer->id,
                'تحويل من صندوق ' . ($fromCaisse->user->name ?? $fromCaisse->id),
                $user->id
            );

            DB::commit();

            return response()->json([
                'message' => 'تم التحويل بنجاح',
                'transfer' => $transfer->load(['fromCaisse.user:id,name', 'toCaisse.user:id,name', 'creator:id,name']),
                'from_caisse' => $fromCaisse->fresh(),
                'to_caisse' => $toCaisse->fresh(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Admin: summary of all caisses
     */
    public function summary(Request $request)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $caisses = Caisse::with('user:id,name,role')->where('is_active', true)->get();

        $totalBalance = $caisses->sum('balance');
        $totalCaisses = $caisses->count();

        $byType = $caisses->groupBy('type')->map(function ($group) {
            return [
                'count' => $group->count(),
                'total_balance' => $group->sum('balance'),
            ];
        });

        // Today's transactions summary
        $todayIn = DB::table('caisse_transactions')
            ->whereDate('created_at', today())
            ->where('type', 'in')
            ->sum('amount');

        $todayOut = DB::table('caisse_transactions')
            ->whereDate('created_at', today())
            ->where('type', 'out')
            ->sum('amount');

        // Today's settlements
        $todaySettled = DB::table('caisse_settlements')
            ->whereDate('created_at', today())
            ->sum('amount');

        return response()->json([
            'total_balance' => (float) $totalBalance,
            'total_caisses' => $totalCaisses,
            'by_type' => $byType,
            'today' => [
                'total_in' => (float) $todayIn,
                'total_out' => (float) $todayOut,
                'total_settled' => (float) $todaySettled,
            ],
            'caisses' => $caisses,
        ]);
    }
}
