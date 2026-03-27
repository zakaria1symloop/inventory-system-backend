<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockTransferController extends Controller
{
    /**
     * List transfers (filterable by warehouse, status)
     */
    public function index(Request $request)
    {
        $query = StockTransfer::with(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'collector', 'approver', 'items.product']);

        if ($request->from_warehouse_id) {
            $query->where('from_warehouse_id', $request->from_warehouse_id);
        }

        if ($request->to_warehouse_id) {
            $query->where('to_warehouse_id', $request->to_warehouse_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        // Filter active (pending+loading) vs archived (collected)
        if ($request->has('active') && $request->active) {
            $query->whereIn('status', [StockTransfer::STATUS_PENDING, StockTransfer::STATUS_LOADING]);
        }
        if ($request->has('archived') && $request->archived) {
            $query->where('status', StockTransfer::STATUS_COLLECTED);
        }

        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $transfers = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($transfers);
    }

    /**
     * Create a new transfer request (status = pending)
     * Can be created by admin or driver (driver requests products)
     */
    public function store(Request $request)
    {
        $request->validate([
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_id' => 'required|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $transfer = StockTransfer::create([
                'from_warehouse_id' => $request->from_warehouse_id,
                'to_warehouse_id' => $request->to_warehouse_id,
                'created_by' => auth()->id(),
                'status' => StockTransfer::STATUS_PENDING,
                'notes' => $request->notes,
            ]);

            foreach ($request->items as $item) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                ]);
            }

            DB::commit();

            return response()->json(
                $transfer->load(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'items.product']),
                201
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Update a pending transfer (warehouses, notes, items)
     */
    public function update(Request $request, StockTransfer $stockTransfer)
    {
        if (!$stockTransfer->isPending()) {
            return response()->json(['message' => 'لا يمكن تعديل تحويل تمت الموافقة عليه'], 400);
        }

        $request->validate([
            'from_warehouse_id' => 'sometimes|required|exists:warehouses,id',
            'to_warehouse_id' => 'sometimes|required|exists:warehouses,id',
            'notes' => 'nullable|string',
            'items' => 'sometimes|required|array|min:1',
            'items.*.product_id' => 'required_with:items|exists:products,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
        ]);

        if ($request->has('from_warehouse_id') && $request->has('to_warehouse_id')) {
            if ($request->from_warehouse_id == $request->to_warehouse_id) {
                return response()->json(['message' => 'المستودع المصدر والوجهة يجب أن يكونا مختلفين'], 422);
            }
        }

        DB::beginTransaction();

        try {
            $stockTransfer->update($request->only(['from_warehouse_id', 'to_warehouse_id', 'notes']));

            if ($request->has('items')) {
                $stockTransfer->items()->delete();
                foreach ($request->items as $item) {
                    StockTransferItem::create([
                        'stock_transfer_id' => $stockTransfer->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                    ]);
                }
            }

            DB::commit();

            return response()->json(
                $stockTransfer->load(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'items.product'])
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Show single transfer
     */
    public function show(StockTransfer $stockTransfer)
    {
        return response()->json(
            $stockTransfer->load(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'collector', 'approver', 'items.product'])
        );
    }

    /**
     * Admin approves the request → status becomes "loading" (chargement)
     * Validates stock availability in source warehouse
     */
    public function approve(StockTransfer $stockTransfer)
    {
        if (!$stockTransfer->isPending()) {
            return response()->json(['message' => 'هذا التحويل تمت الموافقة عليه بالفعل'], 400);
        }

        // Validate stock availability in source warehouse
        $stockErrors = [];
        foreach ($stockTransfer->items as $item) {
            $stock = Stock::where('product_id', $item->product_id)
                ->where('warehouse_id', $stockTransfer->from_warehouse_id)
                ->first();

            $available = $stock ? $stock->quantity : 0;

            if ($available < $item->quantity) {
                $product = Product::find($item->product_id);
                $stockErrors[] = ($product->name ?? 'غير معروف') . ": المطلوب {$item->quantity}، المتوفر {$available}";
            }
        }

        if (count($stockErrors) > 0) {
            return response()->json([
                'message' => 'الكمية غير متوفرة في المخزن المصدر',
                'errors' => $stockErrors,
            ], 400);
        }

        $stockTransfer->status = StockTransfer::STATUS_LOADING;
        $stockTransfer->approved_by = auth()->id();
        $stockTransfer->approved_at = now();
        $stockTransfer->save();

        return response()->json(
            $stockTransfer->load(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'approver', 'items.product'])
        );
    }

    /**
     * Collect/Start - moves stock from source to destination warehouse
     * Status: loading → collected (driver can now sell)
     */
    public function collect(Request $request, StockTransfer $stockTransfer)
    {
        if (!$stockTransfer->isLoading()) {
            if ($stockTransfer->isPending()) {
                return response()->json(['message' => 'يجب الموافقة على التحويل أولاً قبل الاستلام'], 400);
            }
            return response()->json(['message' => 'هذا التحويل تم استلامه بالفعل'], 400);
        }

        DB::beginTransaction();

        try {
            // Validate stock still available
            $stockErrors = [];
            foreach ($stockTransfer->items as $item) {
                $stock = Stock::where('product_id', $item->product_id)
                    ->where('warehouse_id', $stockTransfer->from_warehouse_id)
                    ->first();

                $available = $stock ? $stock->quantity : 0;

                if ($available < $item->quantity) {
                    $product = Product::find($item->product_id);
                    $stockErrors[] = ($product->name ?? 'غير معروف') . ": المطلوب {$item->quantity}، المتوفر {$available}";
                }
            }

            if (count($stockErrors) > 0) {
                return response()->json([
                    'message' => 'الكمية لم تعد متوفرة في المخزن المصدر',
                    'errors' => $stockErrors,
                ], 400);
            }

            // Move stock: deduct from source, add to destination
            $totalCostValue = 0;
            foreach ($stockTransfer->items as $item) {
                // Deduct from source warehouse
                StockMovement::record(
                    $item->product_id,
                    $stockTransfer->from_warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_TRANSFER,
                    $stockTransfer->reference,
                    $stockTransfer,
                    null,
                    'تحويل إلى ' . ($stockTransfer->toWarehouse->name ?? '')
                );

                // Add to destination warehouse
                StockMovement::record(
                    $item->product_id,
                    $stockTransfer->to_warehouse_id,
                    $item->quantity,
                    StockMovement::TYPE_TRANSFER_IN,
                    $stockTransfer->reference,
                    $stockTransfer,
                    null,
                    'استلام تحويل من ' . ($stockTransfer->fromWarehouse->name ?? '')
                );

                // Calculate total cost value
                $product = Product::find($item->product_id);
                $totalCostValue += $item->quantity * ($product->cost_price ?? 0);
            }

            $stockTransfer->status = StockTransfer::STATUS_COLLECTED;
            $stockTransfer->collected_by = auth()->id();
            $stockTransfer->collected_at = now();
            $stockTransfer->save();

            DB::commit();

            return response()->json(
                $stockTransfer->load(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'collector', 'approver', 'items.product'])
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    /**
     * Cancel a pending or loading transfer
     */
    public function destroy(StockTransfer $stockTransfer)
    {
        if ($stockTransfer->isCollected()) {
            return response()->json(['message' => 'لا يمكن حذف تحويل تم استلامه'], 400);
        }

        $stockTransfer->delete();

        return response()->json(['message' => 'تم حذف التحويل']);
    }

    /**
     * Get pending/loading transfers for the current user's warehouse
     */
    public function myPendingTransfers()
    {
        $user = auth()->user();

        if (!$user->warehouse_id) {
            return response()->json([]);
        }

        $transfers = StockTransfer::with(['fromWarehouse.assignedUser:id,name,role,warehouse_id', 'toWarehouse.assignedUser:id,name,role,warehouse_id', 'creator', 'approver', 'items.product'])
            ->where('to_warehouse_id', $user->warehouse_id)
            ->whereIn('status', [StockTransfer::STATUS_PENDING, StockTransfer::STATUS_LOADING])
            ->latest()
            ->get();

        return response()->json($transfers);
    }

    /**
     * Get my warehouse stock (for cashvan driver)
     */
    public function myStock(Request $request)
    {
        $user = auth()->user();

        $warehouseId = $user->warehouse_id;
        if (!$warehouseId) {
            // Fallback to main warehouse
            $warehouseId = Warehouse::where('is_main', true)->value('id');
            if (!$warehouseId) {
                return response()->json([]);
            }
        }

        $stocks = Stock::with('product.categoryPrices')
            ->where('warehouse_id', $warehouseId)
            ->where('quantity', '>', 0)
            ->get()
            ->map(function ($stock) {
                return [
                    'product_id' => $stock->product_id,
                    'product' => $stock->product,
                    'quantity' => $stock->quantity,
                ];
            });

        return response()->json($stocks);
    }

    /**
     * Get my sales (sales from my warehouse)
     */
    public function mySales(Request $request)
    {
        $user = auth()->user();

        $warehouseId = $user->warehouse_id;
        if (!$warehouseId) {
            $warehouseId = Warehouse::where('is_main', true)->value('id');
            if (!$warehouseId) {
                return response()->json(['data' => []]);
            }
        }

        $query = \App\Models\Sale::with(['client', 'items.product', 'warehouse'])
            ->where('warehouse_id', $warehouseId)
            ->where('user_id', $user->id);

        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        $sales = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($sales);
    }
}
