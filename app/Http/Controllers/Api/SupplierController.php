<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request)
    {
        $query = Supplier::query();

        if ($request->active_only) {
            $query->active();
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('company_name', 'like', "%{$request->search}%")
                  ->orWhere('phone', 'like', "%{$request->search}%");
            });
        }

        if ($request->has_balance) {
            $query->where('balance', '>', 0);
        }

        $suppliers = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($suppliers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'company_name' => 'nullable|string',
            'tax_number' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $supplier = Supplier::create($request->all());

        return response()->json($supplier, 201);
    }

    public function show(Supplier $supplier)
    {
        return response()->json($supplier->load(['purchases', 'purchaseReturns']));
    }

    public function update(Request $request, Supplier $supplier)
    {
        $request->validate([
            'name' => 'string|max:255',
            'phone' => 'nullable|string',
            'email' => 'nullable|email',
            'address' => 'nullable|string',
            'company_name' => 'nullable|string',
            'tax_number' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $supplier->update($request->all());

        return response()->json($supplier);
    }

    public function destroy(Supplier $supplier)
    {
        $supplier->delete();

        return response()->json(['message' => 'تم حذف المورد بنجاح']);
    }

    public function getBalance(Supplier $supplier)
    {
        return response()->json(['balance' => $supplier->balance]);
    }

    public function getPurchases(Supplier $supplier, Request $request)
    {
        $purchases = $supplier->purchases()
            ->with(['items.product', 'user', 'warehouse'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($purchases);
    }

    /**
     * Get all suppliers with outstanding debt (creditors - we owe them money)
     */
    public function getCreditors(Request $request)
    {
        $query = \App\Models\Purchase::select(
                'supplier_id',
                \Illuminate\Support\Facades\DB::raw('SUM(grand_total) as total_due'),
                \Illuminate\Support\Facades\DB::raw('SUM(paid_amount) as total_paid'),
                \Illuminate\Support\Facades\DB::raw('SUM(due_amount) as total_remaining'),
                \Illuminate\Support\Facades\DB::raw('COUNT(*) as total_purchases')
            )
            ->where('due_amount', '>', 0)
            ->groupBy('supplier_id')
            ->having('total_remaining', '>', 0);

        $creditors = $query->get()->map(function ($item) {
            $supplier = Supplier::find($item->supplier_id);
            return [
                'supplier_id' => $item->supplier_id,
                'supplier_name' => $supplier->name ?? 'مورد غير معروف',
                'supplier_phone' => $supplier->phone ?? '',
                'supplier_company' => $supplier->company_name ?? '',
                'supplier_address' => $supplier->address ?? '',
                'total_due' => (float) $item->total_due,
                'total_paid' => (float) $item->total_paid,
                'total_remaining' => (float) $item->total_remaining,
                'total_purchases' => (int) $item->total_purchases,
                'supplier_balance' => $supplier ? (float) $supplier->balance : 0,
            ];
        });

        // Sort by remaining amount descending
        $creditors = $creditors->sortByDesc('total_remaining')->values();

        $totals = [
            'total_creditors' => $creditors->count(),
            'total_due' => $creditors->sum('total_due'),
            'total_paid' => $creditors->sum('total_paid'),
            'total_remaining' => $creditors->sum('total_remaining'),
        ];

        return response()->json([
            'data' => $creditors,
            'totals' => $totals,
        ]);
    }

    /**
     * Get unpaid purchases for a specific supplier
     */
    public function getSupplierDebt(Request $request, $supplierId)
    {
        $purchases = \App\Models\Purchase::with(['warehouse', 'user'])
            ->where('supplier_id', $supplierId)
            ->where('due_amount', '>', 0)
            ->orderByDesc('date')
            ->get()
            ->map(function ($purchase) {
                return [
                    'id' => $purchase->id,
                    'reference' => $purchase->reference,
                    'warehouse_name' => $purchase->warehouse->name ?? '',
                    'date' => $purchase->date,
                    'status' => $purchase->status,
                    'payment_status' => $purchase->payment_status,
                    'grand_total' => (float) $purchase->grand_total,
                    'paid_amount' => (float) $purchase->paid_amount,
                    'due_amount' => (float) $purchase->due_amount,
                    'days_old' => $purchase->date ? \Carbon\Carbon::parse($purchase->date)->diffInDays(now()) : null,
                ];
            });

        $supplier = Supplier::find($supplierId);

        return response()->json([
            'supplier' => $supplier ? [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'company_name' => $supplier->company_name,
                'address' => $supplier->address,
                'balance' => (float) $supplier->balance,
            ] : null,
            'purchases' => $purchases,
            'totals' => [
                'total_due' => $purchases->sum('grand_total'),
                'total_paid' => $purchases->sum('paid_amount'),
                'total_remaining' => $purchases->sum('due_amount'),
            ],
        ]);
    }
}
