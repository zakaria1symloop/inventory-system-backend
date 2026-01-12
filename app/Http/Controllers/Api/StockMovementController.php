<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockMovement;
use App\Models\PurchaseReturn;
use App\Models\SaleReturn;
use Illuminate\Http\Request;

class StockMovementController extends Controller
{
    public function index(Request $request)
    {
        $query = StockMovement::with(['product', 'warehouse', 'user']);

        if ($request->product_id) {
            $query->byProduct($request->product_id);
        }

        if ($request->warehouse_id) {
            $query->byWarehouse($request->warehouse_id);
        }

        if ($request->type) {
            $query->byType($request->type);
        }

        if ($request->direction === 'incoming') {
            $query->incoming();
        } elseif ($request->direction === 'outgoing') {
            $query->outgoing();
        }

        if ($request->from_date || $request->to_date) {
            $query->dateRange($request->from_date, $request->to_date);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('reference', 'like', "%{$request->search}%")
                  ->orWhereHas('product', function ($p) use ($request) {
                      $p->where('name', 'like', "%{$request->search}%");
                  });
            });
        }

        $movements = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($movements);
    }

    public function getByProduct(Request $request, $productId)
    {
        $query = StockMovement::with(['warehouse', 'user'])
            ->where('product_id', $productId);

        if ($request->warehouse_id) {
            $query->byWarehouse($request->warehouse_id);
        }

        if ($request->type) {
            $query->byType($request->type);
        }

        if ($request->from_date || $request->to_date) {
            $query->dateRange($request->from_date, $request->to_date);
        }

        $movements = $query->latest()->paginate($request->per_page ?? 20);

        return response()->json($movements);
    }

    public function getSummary(Request $request)
    {
        $query = StockMovement::query();

        if ($request->from_date || $request->to_date) {
            $query->dateRange($request->from_date, $request->to_date);
        }

        if ($request->warehouse_id) {
            $query->byWarehouse($request->warehouse_id);
        }

        $summary = [
            'total_purchases' => (clone $query)->byType(StockMovement::TYPE_PURCHASE)->sum('quantity_change'),
            'total_purchase_returns' => abs((clone $query)->byType(StockMovement::TYPE_PURCHASE_RETURN)->sum('quantity_change')),
            'total_sales' => abs((clone $query)->byType(StockMovement::TYPE_SALE)->sum('quantity_change')),
            'total_sale_returns' => (clone $query)->byType(StockMovement::TYPE_SALE_RETURN)->sum('quantity_change'),
            'total_adjustments' => (clone $query)->byType(StockMovement::TYPE_ADJUSTMENT)->sum('quantity_change'),
            'total_deliveries' => abs((clone $query)->byType(StockMovement::TYPE_DELIVERY)->sum('quantity_change')),
            'total_delivery_returns' => (clone $query)->byType(StockMovement::TYPE_DELIVERY_RETURN)->sum('quantity_change'),
        ];

        $summary['net_movement'] = $summary['total_purchases']
            - $summary['total_purchase_returns']
            - $summary['total_sales']
            + $summary['total_sale_returns']
            + $summary['total_adjustments']
            - $summary['total_deliveries']
            + $summary['total_delivery_returns'];

        return response()->json($summary);
    }

    // Purchase Returns listing
    public function purchaseReturns(Request $request)
    {
        $query = PurchaseReturn::with(['purchase', 'supplier', 'warehouse', 'user', 'items.product']);

        if ($request->supplier_id) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        if ($request->search) {
            $query->where('reference', 'like', "%{$request->search}%");
        }

        $returns = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($returns);
    }

    public function purchaseReturnShow($id)
    {
        $return = PurchaseReturn::with(['purchase', 'supplier', 'warehouse', 'user', 'items.product'])
            ->findOrFail($id);

        return response()->json($return);
    }

    // Sale Returns listing
    public function saleReturns(Request $request)
    {
        $query = SaleReturn::with(['sale', 'client', 'warehouse', 'user', 'items.product']);

        if ($request->client_id) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->from_date) {
            $query->whereDate('date', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('date', '<=', $request->to_date);
        }

        if ($request->search) {
            $query->where('reference', 'like', "%{$request->search}%");
        }

        $returns = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($returns);
    }

    public function saleReturnShow($id)
    {
        $return = SaleReturn::with(['sale', 'client', 'warehouse', 'user', 'items.product'])
            ->findOrFail($id);

        return response()->json($return);
    }
}
