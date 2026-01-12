<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Adjustment;
use App\Models\AdjustmentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdjustmentController extends Controller
{
    public function index(Request $request)
    {
        $query = Adjustment::with(['warehouse', 'user', 'approvedBy']);

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        $adjustments = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($adjustments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'date' => 'required|date',
            'type' => 'required|in:addition,subtraction',
            'reason' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|numeric|min:0.01',
            'items.*.unit_price' => 'required|numeric|min:0',
            'items.*.reason' => 'nullable|string',
        ]);

        DB::beginTransaction();

        try {
            $adjustment = Adjustment::create([
                'warehouse_id' => $request->warehouse_id,
                'user_id' => auth()->id(),
                'date' => $request->date,
                'type' => $request->type,
                'reason' => $request->reason,
                'status' => 'pending',
            ]);

            foreach ($request->items as $item) {
                AdjustmentItem::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'unit_price' => $item['unit_price'],
                    'reason' => $item['reason'] ?? null,
                ]);
            }

            $adjustment->calculateTotals();

            DB::commit();

            return response()->json($adjustment->load(['warehouse', 'user', 'items.product']), 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    public function show(Adjustment $adjustment)
    {
        return response()->json($adjustment->load(['warehouse', 'user', 'approvedBy', 'items.product']));
    }

    public function approve(Adjustment $adjustment)
    {
        if ($adjustment->status !== 'pending') {
            return response()->json(['message' => 'التعديل ليس في حالة انتظار'], 400);
        }

        $adjustment->approve(auth()->id());

        return response()->json($adjustment->load(['approvedBy', 'items.product']));
    }

    public function reject(Adjustment $adjustment)
    {
        if ($adjustment->status !== 'pending') {
            return response()->json(['message' => 'التعديل ليس في حالة انتظار'], 400);
        }

        $adjustment->reject(auth()->id());

        return response()->json($adjustment);
    }

    public function destroy(Adjustment $adjustment)
    {
        // Security: Only pending adjustments can be deleted
        if ($adjustment->status !== 'pending') {
            return response()->json([
                'message' => 'لا يمكن حذف التعديل. فقط التعديلات المعلقة يمكن حذفها للحفاظ على التتبع'
            ], 400);
        }

        // Security: Only admin or the creator can delete pending adjustments
        if (auth()->user()->role !== 'admin' && auth()->id() !== $adjustment->user_id) {
            return response()->json([
                'message' => 'لا تملك صلاحية حذف هذا التعديل'
            ], 403);
        }

        $adjustment->delete();

        return response()->json(['message' => 'تم حذف التعديل بنجاح']);
    }
}
