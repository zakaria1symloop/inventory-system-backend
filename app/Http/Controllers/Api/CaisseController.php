<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\CaisseSettlement;
use App\Models\CaisseTransfer;
use App\Traits\LocalizesMessages;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CaisseController extends Controller
{
    use LocalizesMessages;

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
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'غير مصرح',
                    'fr' => 'Non autorisé',
                    'en' => 'Not authorized',
                ]),
            ], 403);
        }

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'nullable|string|max:255',
        ]);

        $targetUser = \App\Models\User::findOrFail($request->user_id);

        // Only admin can have multiple caisses
        if ($targetUser->role !== 'admin' && Caisse::where('user_id', $targetUser->id)->exists()) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'هذا المستخدم لديه صندوق بالفعل',
                    'fr' => 'Cet utilisateur possède déjà une caisse',
                    'en' => 'This user already has a caisse',
                ]),
            ], 422);
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
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'غير مصرح',
                    'fr' => 'Non autorisé',
                    'en' => 'Not authorized',
                ]),
            ], 403);
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
    public function show(Request $request, $id)
    {
        $user = auth()->user();

        $caisse = Caisse::with(['user:id,name,role,phone'])->findOrFail($id);

        if (!$user->isAdmin() && !$user->isManager() && $caisse->user_id !== $user->id) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'غير مصرح',
                    'fr' => 'Non autorisé',
                    'en' => 'Not authorized',
                ]),
            ], 403);
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
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'غير مصرح',
                    'fr' => 'Non autorisé',
                    'en' => 'Not authorized',
                ]),
            ], 403);
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
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'فقط المدير يمكنه إجراء التحصيل',
                    'fr' => 'Seul le gestionnaire peut effectuer un encaissement',
                    'en' => 'Only managers can collect settlements',
                ]),
            ], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:admin_collect,seller_deposit',
            'notes' => 'nullable|string',
        ]);

        $caisse = Caisse::findOrFail($id);

        if ($request->amount > $caisse->balance) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'المبلغ أكبر من الرصيد الحالي',
                    'fr' => 'Le montant dépasse le solde actuel',
                    'en' => 'Amount exceeds current balance',
                ]),
            ], 422);
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
                'message' => $this->localize($request, [
                    'ar' => 'تمت عملية التحصيل بنجاح',
                    'fr' => 'Encaissement effectué avec succès',
                    'en' => 'Settlement completed successfully',
                ]),
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
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'لا يوجد صندوق لهذا المستخدم',
                    'fr' => "Aucune caisse n'est associée à cet utilisateur",
                    'en' => 'No caisse for this user',
                ]),
            ], 404);
        }

        $recentTransactions = $caisse->transactions()
            ->with('creator:id,name')
            ->latest()
            ->take(20)
            ->get();

        // ERP breakdown: keep the money streams separate (delivery sales vs
        // old-debt recovery vs other), for both the whole caisse life and today.
        $startOfDay = now()->startOfDay();

        $sumIn = function ($sourceType = null, $since = null) use ($caisse) {
            $q = $caisse->transactions()->where('type', 'in');
            if ($sourceType !== null) $q->where('source_type', $sourceType);
            if ($since !== null) $q->where('created_at', '>=', $since);
            return (float) $q->sum('amount');
        };
        $sumOut = function ($since = null) use ($caisse) {
            $q = $caisse->transactions()->where('type', 'out');
            if ($since !== null) $q->where('created_at', '>=', $since);
            return (float) $q->sum('amount');
        };

        $totalIn = $sumIn();
        $deliveryIn = $sumIn('delivery');
        $debtIn = $sumIn('debt_collection');

        return response()->json([
            'caisse' => $caisse,
            'recent_transactions' => $recentTransactions,
            'summary' => [
                'total_in' => $totalIn,
                'total_out' => $sumOut(),
                'delivery_in' => $deliveryIn,            // payments for the delivered orders
                'debt_collection_in' => $debtIn,         // recovery of previous debt
                'other_in' => max(0, $totalIn - $deliveryIn - $debtIn),
                'today' => [
                    'total_in' => $sumIn(null, $startOfDay),
                    'total_out' => $sumOut($startOfDay),
                    'delivery_in' => $sumIn('delivery', $startOfDay),
                    'debt_collection_in' => $sumIn('debt_collection', $startOfDay),
                ],
            ],
        ]);
    }

    /**
     * Admin/Manager: transfer money between caisses
     */
    public function transfer(Request $request)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'فقط المدير يمكنه إجراء التحويل',
                    'fr' => 'Seul le gestionnaire peut effectuer un transfert',
                    'en' => 'Only managers can transfer funds',
                ]),
            ], 403);
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
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'المبلغ أكبر من رصيد الصندوق المصدر',
                    'fr' => 'Le montant dépasse le solde de la caisse source',
                    'en' => 'Amount exceeds source caisse balance',
                ]),
            ], 422);
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
                'message' => $this->localize($request, [
                    'ar' => 'تم التحويل بنجاح',
                    'fr' => 'Transfert effectué avec succès',
                    'en' => 'Transfer completed successfully',
                ]),
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
     * Admin: adjust caisse balance (add or remove money)
     */
    public function adjust(Request $request, $id)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'فقط المدير يمكنه تعديل الرصيد',
                    'fr' => 'Seul le gestionnaire peut ajuster le solde',
                    'en' => 'Only managers can adjust the balance',
                ]),
            ], 403);
        }

        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:add,remove',
            'notes' => 'nullable|string|max:500',
        ]);

        $caisse = Caisse::findOrFail($id);

        if ($request->type === 'remove' && $request->amount > $caisse->balance) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'المبلغ أكبر من الرصيد الحالي',
                    'fr' => 'Le montant dépasse le solde actuel',
                    'en' => 'Amount exceeds current balance',
                ]),
            ], 422);
        }

        $txType = $request->type === 'add' ? 'in' : 'out';
        $description = $request->type === 'add'
            ? 'إضافة رصيد: ' . ($request->notes ?? 'تعديل إداري')
            : 'خصم رصيد: ' . ($request->notes ?? 'تعديل إداري');

        $caisse->addTransaction(
            $txType,
            $request->amount,
            'adjustment',
            null,
            $description,
            $user->id
        );

        return response()->json([
            'message' => $request->type === 'add'
                ? $this->localize($request, [
                    'ar' => 'تم إضافة الرصيد بنجاح',
                    'fr' => 'Solde ajouté avec succès',
                    'en' => 'Balance added successfully',
                ])
                : $this->localize($request, [
                    'ar' => 'تم خصم الرصيد بنجاح',
                    'fr' => 'Solde déduit avec succès',
                    'en' => 'Balance deducted successfully',
                ]),
            'caisse' => $caisse->fresh()->load('user:id,name,role'),
        ]);
    }

    /**
     * Admin: summary of all caisses
     */
    public function summary(Request $request)
    {
        $user = auth()->user();

        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'غير مصرح',
                    'fr' => 'Non autorisé',
                    'en' => 'Not authorized',
                ]),
            ], 403);
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

    /**
     * Aggregated caisse in/out totals for an arbitrary date range.
     * Used by reports/profit-loss to show real cash movements
     * (so the P&L page agrees with the caisses module).
     */
    public function periodSummary(Request $request)
    {
        $user = auth()->user();
        if (!$user->isAdmin() && !$user->isManager()) {
            return response()->json([
                'message' => $this->localize($request, [
                    'ar' => 'غير مصرح',
                    'fr' => 'Non autorisé',
                    'en' => 'Not authorized',
                ]),
            ], 403);
        }

        $request->validate([
            'from_date' => 'nullable|date',
            'to_date' => 'nullable|date',
        ]);

        $from = $request->from_date ? \Carbon\Carbon::parse($request->from_date)->startOfDay() : \Carbon\Carbon::now()->startOfMonth();
        $to = $request->to_date ? \Carbon\Carbon::parse($request->to_date)->endOfDay() : \Carbon\Carbon::now()->endOfDay();

        $totalIn = DB::table('caisse_transactions')
            ->whereBetween('created_at', [$from, $to])
            ->where('type', 'in')
            ->sum('amount');

        $totalOut = DB::table('caisse_transactions')
            ->whereBetween('created_at', [$from, $to])
            ->where('type', 'out')
            ->sum('amount');

        return response()->json([
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
            'total_in' => (float) $totalIn,
            'total_out' => (float) $totalOut,
            'net' => (float) $totalIn - (float) $totalOut,
        ]);
    }
}
