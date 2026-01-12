<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Stock;
use App\Models\Product;
use App\Models\Warehouse;
use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    /**
     * Get all inventory (products with stock across all warehouses)
     */
    public function index(Request $request)
    {
        $query = Product::with(['category', 'brand', 'unitSale', 'stock.warehouse'])
            ->withSum('stock as total_stock', 'quantity');

        // Filter by search
        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        // Filter by category
        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        // Filter by stock status
        if ($request->stock_status) {
            switch ($request->stock_status) {
                case 'in_stock':
                    $query->having('total_stock', '>', 0);
                    break;
                case 'low_stock':
                    $query->havingRaw('total_stock > 0 AND total_stock <= stock_alert');
                    break;
                case 'out_of_stock':
                    $query->having('total_stock', '<=', 0);
                    break;
            }
        }

        // Filter by warehouse
        if ($request->warehouse_id) {
            $query->whereHas('stock', function ($q) use ($request) {
                $q->where('warehouse_id', $request->warehouse_id)
                  ->where('quantity', '>', 0);
            });
        }

        $products = $query->orderBy('name')->paginate($request->per_page ?? 50);

        // Add warehouse-specific stock data
        $warehouseId = $request->warehouse_id;
        $products->getCollection()->transform(function ($product) use ($warehouseId) {
            $stockData = [];
            foreach ($product->stock as $stock) {
                $stockData[$stock->warehouse_id] = [
                    'quantity' => $stock->quantity,
                    'warehouse_name' => $stock->warehouse->name ?? 'Unknown',
                ];
            }
            $product->stock_by_warehouse = $stockData;

            // Get available stock (minus reserved)
            if ($warehouseId) {
                $product->available_stock = Stock::getAvailableStock($product->id, $warehouseId);
            }

            return $product;
        });

        return response()->json($products);
    }

    /**
     * Get inventory by warehouse
     */
    public function getByWarehouse(Request $request, $warehouseId)
    {
        $query = Stock::with(['product.category', 'product.brand', 'product.unitSale', 'warehouse'])
            ->where('warehouse_id', $warehouseId);

        // Filter by search
        if ($request->search) {
            $query->whereHas('product', function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        // Filter by stock status
        if ($request->stock_status) {
            switch ($request->stock_status) {
                case 'in_stock':
                    $query->where('quantity', '>', 0);
                    break;
                case 'low_stock':
                    $query->whereHas('product', function ($q) {
                        $q->whereRaw('stock.quantity > 0 AND stock.quantity <= products.stock_alert');
                    });
                    break;
                case 'out_of_stock':
                    $query->where('quantity', '<=', 0);
                    break;
            }
        }

        $stocks = $query->orderBy('quantity', 'asc')->paginate($request->per_page ?? 50);

        // Add available stock (minus reserved)
        $stocks->getCollection()->transform(function ($stock) {
            $stock->available_quantity = Stock::getAvailableStock($stock->product_id, $stock->warehouse_id);
            $stock->reserved_quantity = $stock->quantity - $stock->available_quantity;
            return $stock;
        });

        return response()->json($stocks);
    }

    /**
     * Adjust stock (add or remove)
     */
    public function adjust(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'quantity' => 'required|numeric',
            'type' => 'required|in:add,remove,set',
            'reason' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $stock = Stock::firstOrCreate(
                [
                    'product_id' => $request->product_id,
                    'warehouse_id' => $request->warehouse_id,
                ],
                ['quantity' => 0]
            );

            $product = Product::find($request->product_id);
            $previousQty = $stock->quantity;
            $adjustQty = abs($request->quantity);

            switch ($request->type) {
                case 'add':
                    $adjustQty = abs($adjustQty);
                    break;
                case 'remove':
                    if ($stock->quantity < $adjustQty) {
                        throw new \Exception('الكمية المراد إزالتها أكبر من المتوفر');
                    }
                    $adjustQty = -abs($adjustQty);
                    break;
                case 'set':
                    $adjustQty = $request->quantity - $previousQty;
                    break;
            }

            $stock->quantity = $previousQty + $adjustQty;
            $stock->save();

            // Record stock movement
            $isLoss = $request->is_loss && $adjustQty < 0;
            StockMovement::create([
                'product_id' => $request->product_id,
                'warehouse_id' => $request->warehouse_id,
                'user_id' => auth()->id(),
                'type' => StockMovement::TYPE_ADJUSTMENT,
                'reference' => ($isLoss ? 'LOSS-' : 'ADJ-') . date('YmdHis'),
                'movable_type' => Stock::class,
                'movable_id' => $stock->id,
                'quantity_before' => $previousQty,
                'quantity_change' => $adjustQty,
                'quantity_after' => $stock->quantity,
                'unit_cost' => $isLoss ? $product->cost_price : null,
                'note' => $request->reason ?? ($isLoss ? 'خسارة مخزون' : 'تعديل المخزون'),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تعديل المخزون بنجاح',
                'previous_quantity' => $previousQty,
                'new_quantity' => $stock->quantity,
                'adjustment' => $adjustQty,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Physical inventory count
     */
    public function count(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'required|exists:warehouses,id',
            'counted_quantity' => 'required|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            $stock = Stock::firstOrCreate(
                [
                    'product_id' => $request->product_id,
                    'warehouse_id' => $request->warehouse_id,
                ],
                ['quantity' => 0]
            );

            $systemQty = $stock->quantity;
            $countedQty = $request->counted_quantity;
            $difference = $countedQty - $systemQty;

            if ($difference != 0) {
                $stock->quantity = $countedQty;
                $stock->save();

                // Record stock movement
                StockMovement::create([
                    'product_id' => $request->product_id,
                    'warehouse_id' => $request->warehouse_id,
                    'user_id' => auth()->id(),
                    'type' => StockMovement::TYPE_ADJUSTMENT,
                    'reference' => 'COUNT-' . date('YmdHis'),
                    'movable_type' => Stock::class,
                    'movable_id' => $stock->id,
                    'quantity_before' => $systemQty,
                    'quantity_change' => $difference,
                    'quantity_after' => $countedQty,
                    'note' => 'جرد مخزون: ' . ($request->notes ?? 'بدون ملاحظات'),
                ]);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم تسجيل الجرد بنجاح',
                'system_quantity' => $systemQty,
                'counted_quantity' => $countedQty,
                'difference' => $difference,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }

    /**
     * Transfer stock between warehouses
     */
    public function transfer(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'from_warehouse_id' => 'required|exists:warehouses,id',
            'to_warehouse_id' => 'required|exists:warehouses,id|different:from_warehouse_id',
            'quantity' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string|max:500',
        ]);

        DB::beginTransaction();

        try {
            // Check source stock
            $sourceStock = Stock::where('product_id', $request->product_id)
                ->where('warehouse_id', $request->from_warehouse_id)
                ->first();

            if (!$sourceStock || $sourceStock->quantity < $request->quantity) {
                throw new \Exception('الكمية غير متوفرة في المستودع المصدر');
            }

            // Remove from source
            $sourceStock->quantity -= $request->quantity;
            $sourceStock->save();

            // Add to destination
            $destStock = Stock::firstOrCreate(
                [
                    'product_id' => $request->product_id,
                    'warehouse_id' => $request->to_warehouse_id,
                ],
                ['quantity' => 0]
            );
            $destStock->quantity += $request->quantity;
            $destStock->save();

            $transferRef = 'TRF-' . date('YmdHis');
            $fromWarehouse = Warehouse::find($request->from_warehouse_id);
            $toWarehouse = Warehouse::find($request->to_warehouse_id);

            // Record source movement (out)
            StockMovement::create([
                'product_id' => $request->product_id,
                'warehouse_id' => $request->from_warehouse_id,
                'user_id' => auth()->id(),
                'type' => StockMovement::TYPE_TRANSFER,
                'reference' => $transferRef,
                'movable_type' => Stock::class,
                'movable_id' => $sourceStock->id,
                'quantity_before' => $sourceStock->quantity + $request->quantity,
                'quantity_change' => -$request->quantity,
                'quantity_after' => $sourceStock->quantity,
                'note' => 'تحويل إلى: ' . $toWarehouse->name,
            ]);

            // Record destination movement (in)
            StockMovement::create([
                'product_id' => $request->product_id,
                'warehouse_id' => $request->to_warehouse_id,
                'user_id' => auth()->id(),
                'type' => StockMovement::TYPE_TRANSFER,
                'reference' => $transferRef,
                'movable_type' => Stock::class,
                'movable_id' => $destStock->id,
                'quantity_before' => $destStock->quantity - $request->quantity,
                'quantity_change' => $request->quantity,
                'quantity_after' => $destStock->quantity,
                'note' => 'تحويل من: ' . $fromWarehouse->name,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'تم التحويل بنجاح',
                'quantity_transferred' => $request->quantity,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
}
