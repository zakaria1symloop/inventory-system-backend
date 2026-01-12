<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Delivery;
use App\Models\DeliveryOrder;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Product;
use App\Models\Client;
use App\Models\User;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Purchase;
use App\Models\Dispense;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class ReportController extends Controller
{
    // ===================== SALES REPORTS =====================

    /**
     * Sales Summary Report
     */
    public function salesSummary(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        // Orders summary
        $orderStats = Order::whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('
                COUNT(*) as total_orders,
                SUM(CASE WHEN status = "delivered" THEN 1 ELSE 0 END) as delivered_orders,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(CASE WHEN status = "pending" THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = "delivered" THEN grand_total ELSE 0 END) as total_revenue,
                SUM(grand_total) as total_value
            ')
            ->first();

        // Sales by day
        $salesByDay = Order::whereBetween('created_at', [$fromDate, $toDate])
            ->where('status', 'delivered')
            ->selectRaw('DATE(created_at) as date, COUNT(*) as orders, SUM(grand_total) as revenue')
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get();

        // Top products
        $topProducts = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereBetween('orders.created_at', [$fromDate, $toDate])
            ->where('orders.status', 'delivered')
            ->selectRaw('
                products.id,
                products.name,
                products.barcode,
                SUM(order_items.quantity_delivered) as total_quantity,
                SUM(order_items.subtotal) as total_revenue
            ')
            ->groupBy('products.id', 'products.name', 'products.barcode')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();

        // Top clients
        $topClients = Order::join('clients', 'orders.client_id', '=', 'clients.id')
            ->whereBetween('orders.created_at', [$fromDate, $toDate])
            ->where('orders.status', 'delivered')
            ->selectRaw('
                clients.id,
                clients.name,
                clients.phone,
                COUNT(*) as total_orders,
                SUM(orders.grand_total) as total_revenue
            ')
            ->groupBy('clients.id', 'clients.name', 'clients.phone')
            ->orderByDesc('total_revenue')
            ->limit(10)
            ->get();

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'summary' => $orderStats,
            'sales_by_day' => $salesByDay,
            'top_products' => $topProducts,
            'top_clients' => $topClients,
        ]);
    }

    /**
     * Detailed Sales Report - By Product
     */
    public function salesByProduct(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $query = OrderItem::join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereBetween('orders.created_at', [$fromDate, $toDate])
            ->where('orders.status', 'delivered');

        if ($request->category_id) {
            $query->where('products.category_id', $request->category_id);
        }

        $data = $query->selectRaw('
                products.id,
                products.name,
                products.barcode,
                categories.name as category_name,
                products.retail_price,
                products.cost_price,
                SUM(order_items.quantity_delivered) as total_quantity,
                SUM(order_items.subtotal) as total_revenue,
                SUM(order_items.quantity_delivered * products.cost_price) as total_cost,
                SUM(order_items.subtotal) - SUM(order_items.quantity_delivered * products.cost_price) as profit
            ')
            ->groupBy('products.id', 'products.name', 'products.barcode', 'categories.name', 'products.retail_price', 'products.cost_price')
            ->orderByDesc('total_revenue')
            ->get();

        $totals = [
            'total_quantity' => $data->sum('total_quantity'),
            'total_revenue' => $data->sum('total_revenue'),
            'total_cost' => $data->sum('total_cost'),
            'total_profit' => $data->sum('profit'),
        ];

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'data' => $data,
            'totals' => $totals,
        ]);
    }

    /**
     * Detailed Sales Report - By Client
     */
    public function salesByClient(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $data = Order::join('clients', 'orders.client_id', '=', 'clients.id')
            ->whereBetween('orders.created_at', [$fromDate, $toDate])
            ->where('orders.status', 'delivered')
            ->selectRaw('
                clients.id,
                clients.name,
                clients.phone,
                clients.address,
                COUNT(*) as total_orders,
                SUM(orders.grand_total) as total_revenue,
                AVG(orders.grand_total) as avg_order_value,
                MIN(orders.created_at) as first_order,
                MAX(orders.created_at) as last_order
            ')
            ->groupBy('clients.id', 'clients.name', 'clients.phone', 'clients.address')
            ->orderByDesc('total_revenue')
            ->get();

        $totals = [
            'total_clients' => $data->count(),
            'total_orders' => $data->sum('total_orders'),
            'total_revenue' => $data->sum('total_revenue'),
        ];

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'data' => $data,
            'totals' => $totals,
        ]);
    }

    /**
     * Detailed Sales Report - By Seller
     */
    public function salesBySeller(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $data = Order::join('users', 'orders.seller_id', '=', 'users.id')
            ->whereBetween('orders.created_at', [$fromDate, $toDate])
            ->selectRaw('
                users.id,
                users.name,
                COUNT(*) as total_orders,
                SUM(CASE WHEN orders.status = "delivered" THEN 1 ELSE 0 END) as delivered_orders,
                SUM(CASE WHEN orders.status = "cancelled" THEN 1 ELSE 0 END) as cancelled_orders,
                SUM(CASE WHEN orders.status = "delivered" THEN orders.grand_total ELSE 0 END) as total_revenue,
                AVG(CASE WHEN orders.status = "delivered" THEN orders.grand_total ELSE NULL END) as avg_order_value
            ')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_revenue')
            ->get();

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'data' => $data,
        ]);
    }

    // ===================== DELIVERY REPORTS =====================

    /**
     * Delivery Summary Report
     */
    public function deliverySummary(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $summary = Delivery::whereBetween('date', [$fromDate, $toDate])
            ->selectRaw('
                COUNT(*) as total_deliveries,
                SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_deliveries,
                SUM(CASE WHEN status = "in_progress" THEN 1 ELSE 0 END) as in_progress_deliveries,
                SUM(total_orders) as total_orders,
                SUM(delivered_count) as delivered_orders,
                SUM(failed_count) as failed_orders,
                SUM(total_amount) as total_amount,
                SUM(collected_amount) as collected_amount
            ')
            ->first();

        // Calculate success rate
        $successRate = $summary->total_orders > 0
            ? round(($summary->delivered_orders / $summary->total_orders) * 100, 2)
            : 0;

        // By day
        $byDay = Delivery::whereBetween('date', [$fromDate, $toDate])
            ->selectRaw('
                date,
                COUNT(*) as deliveries,
                SUM(delivered_count) as delivered,
                SUM(failed_count) as failed,
                SUM(collected_amount) as collected
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'summary' => array_merge($summary->toArray(), ['success_rate' => $successRate]),
            'by_day' => $byDay,
        ]);
    }

    /**
     * Delivery Report - By Livreur
     */
    public function deliveryByLivreur(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $data = Delivery::join('users', 'deliveries.livreur_id', '=', 'users.id')
            ->whereBetween('deliveries.date', [$fromDate, $toDate])
            ->selectRaw('
                users.id,
                users.name,
                COUNT(*) as total_deliveries,
                SUM(deliveries.total_orders) as total_orders,
                SUM(deliveries.delivered_count) as delivered_orders,
                SUM(deliveries.failed_count) as failed_orders,
                SUM(deliveries.total_amount) as total_amount,
                SUM(deliveries.collected_amount) as collected_amount,
                ROUND((SUM(deliveries.delivered_count) / NULLIF(SUM(deliveries.total_orders), 0)) * 100, 2) as success_rate
            ')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('delivered_orders')
            ->get();

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'data' => $data,
        ]);
    }

    /**
     * Delivery Details - Individual deliveries
     */
    public function deliveryDetails(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $query = Delivery::with(['livreur', 'vehicle'])
            ->whereBetween('date', [$fromDate, $toDate]);

        if ($request->livreur_id) {
            $query->where('livreur_id', $request->livreur_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        $data = $query->orderByDesc('date')
            ->get()
            ->map(function ($delivery) {
                return [
                    'id' => $delivery->id,
                    'reference' => $delivery->reference,
                    'date' => $delivery->date,
                    'livreur_name' => $delivery->livreur->name ?? 'N/A',
                    'vehicle' => $delivery->vehicle->name ?? 'N/A',
                    'status' => $delivery->status,
                    'total_orders' => $delivery->total_orders,
                    'delivered_count' => $delivery->delivered_count,
                    'failed_count' => $delivery->failed_count,
                    'success_rate' => $delivery->total_orders > 0
                        ? round(($delivery->delivered_count / $delivery->total_orders) * 100, 2)
                        : 0,
                    'total_amount' => $delivery->total_amount,
                    'collected_amount' => $delivery->collected_amount,
                    'start_time' => $delivery->start_time,
                    'end_time' => $delivery->end_time,
                ];
            });

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'data' => $data,
        ]);
    }

    // ===================== STOCK REPORTS =====================

    /**
     * Stock Summary Report
     */
    public function stockSummary(Request $request)
    {
        $query = Stock::join('products', 'stock.product_id', '=', 'products.id')
            ->join('warehouses', 'stock.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id');

        if ($request->warehouse_id) {
            $query->where('stock.warehouse_id', $request->warehouse_id);
        }

        if ($request->category_id) {
            $query->where('products.category_id', $request->category_id);
        }

        $data = $query->selectRaw('
                products.id,
                products.name,
                products.barcode,
                categories.name as category_name,
                warehouses.name as warehouse_name,
                stock.quantity,
                products.stock_alert as min_stock,
                products.retail_price,
                products.cost_price,
                stock.quantity * products.cost_price as stock_value,
                CASE WHEN stock.quantity <= products.stock_alert THEN 1 ELSE 0 END as is_low_stock
            ')
            ->orderBy('products.name')
            ->get();

        $totals = [
            'total_products' => $data->count(),
            'total_quantity' => $data->sum('quantity'),
            'total_value' => $data->sum('stock_value'),
            'low_stock_count' => $data->where('is_low_stock', 1)->count(),
        ];

        return response()->json([
            'data' => $data,
            'totals' => $totals,
        ]);
    }

    /**
     * Stock Movements Report
     */
    public function stockMovements(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        $query = StockMovement::with(['product', 'warehouse'])
            ->whereBetween('created_at', [$fromDate, $toDate]);

        if ($request->warehouse_id) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->product_id) {
            $query->where('product_id', $request->product_id);
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        $data = $query->orderByDesc('created_at')
            ->get()
            ->map(function ($movement) {
                $isLoss = str_starts_with($movement->reference ?? '', 'LOSS-');
                return [
                    'id' => $movement->id,
                    'date' => $movement->created_at->format('Y-m-d H:i'),
                    'product_name' => $movement->product->name ?? 'N/A',
                    'product_barcode' => $movement->product->barcode ?? 'N/A',
                    'warehouse_name' => $movement->warehouse->name ?? 'N/A',
                    'type' => $movement->type,
                    'type_label' => $this->getMovementTypeLabel($movement->type, $movement->reference),
                    'is_loss' => $isLoss,
                    'quantity' => $movement->quantity_change,
                    'before_quantity' => $movement->quantity_before,
                    'after_quantity' => $movement->quantity_after,
                    'reference' => $movement->reference,
                    'unit_cost' => $movement->unit_cost,
                    'loss_value' => $isLoss ? abs($movement->quantity_change) * ($movement->unit_cost ?? 0) : null,
                    'notes' => $movement->note,
                ];
            });

        // Summary by type
        $summary = StockMovement::whereBetween('created_at', [$fromDate, $toDate])
            ->selectRaw('type, SUM(quantity_change) as total_quantity, COUNT(*) as count')
            ->groupBy('type')
            ->get();

        // Loss summary
        $lossSummary = StockMovement::whereBetween('created_at', [$fromDate, $toDate])
            ->where('reference', 'like', 'LOSS-%')
            ->selectRaw('
                COUNT(*) as count,
                SUM(ABS(quantity_change)) as total_quantity,
                SUM(ABS(quantity_change) * COALESCE(unit_cost, 0)) as total_value
            ')
            ->first();

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'data' => $data,
            'summary' => $summary,
            'losses' => [
                'count' => $lossSummary->count ?? 0,
                'total_quantity' => $lossSummary->total_quantity ?? 0,
                'total_value' => $lossSummary->total_value ?? 0,
            ],
        ]);
    }

    /**
     * Low Stock Alert Report
     */
    public function lowStockAlert(Request $request)
    {
        $query = Stock::join('products', 'stock.product_id', '=', 'products.id')
            ->join('warehouses', 'stock.warehouse_id', '=', 'warehouses.id')
            ->leftJoin('categories', 'products.category_id', '=', 'categories.id')
            ->whereRaw('stock.quantity <= products.stock_alert');

        if ($request->warehouse_id) {
            $query->where('stock.warehouse_id', $request->warehouse_id);
        }

        $data = $query->selectRaw('
                products.id,
                products.name,
                products.barcode,
                categories.name as category_name,
                warehouses.name as warehouse_name,
                stock.quantity as current_stock,
                products.stock_alert as min_stock,
                products.stock_alert - stock.quantity as shortage
            ')
            ->orderByDesc('shortage')
            ->get();

        return response()->json([
            'data' => $data,
            'total_alerts' => $data->count(),
        ]);
    }

    // ===================== FINANCIAL REPORTS =====================

    /**
     * Financial Summary Report
     */
    public function financialSummary(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        // Sales revenue
        $salesRevenue = Order::whereBetween('created_at', [$fromDate, $toDate])
            ->where('status', 'delivered')
            ->sum('grand_total');

        // Purchase costs
        $purchaseCosts = Purchase::whereBetween('created_at', [$fromDate, $toDate])
            ->where('status', 'received')
            ->sum('grand_total');

        // Collections from deliveries
        $collections = Delivery::whereBetween('date', [$fromDate, $toDate])
            ->sum('collected_amount');

        // Payments received
        $paymentsReceived = Payment::whereBetween('created_at', [$fromDate, $toDate])
            ->where('payable_type', 'App\\Models\\Sale')
            ->sum('amount');

        // Payments made
        $paymentsMade = Payment::whereBetween('created_at', [$fromDate, $toDate])
            ->where('payable_type', 'App\\Models\\Purchase')
            ->sum('amount');

        // Dispenses (Expenses)
        $totalDispenses = Dispense::whereBetween('date', [$fromDate, $toDate])
            ->sum('amount');

        // Dispenses by category
        $dispensesByCategory = Dispense::whereBetween('date', [$fromDate, $toDate])
            ->selectRaw('category, SUM(amount) as total')
            ->groupBy('category')
            ->get();

        // Stock Losses (reference starts with LOSS-)
        $stockLosses = StockMovement::whereBetween('created_at', [$fromDate, $toDate])
            ->where('reference', 'like', 'LOSS-%')
            ->selectRaw('
                COUNT(*) as loss_count,
                SUM(ABS(quantity_change)) as total_quantity,
                SUM(ABS(quantity_change) * COALESCE(unit_cost, 0)) as total_value
            ')
            ->first();

        $totalLossValue = $stockLosses->total_value ?? 0;

        // Outstanding from clients
        $clientsOutstanding = Client::sum('balance');

        // Outstanding to suppliers
        $suppliersOutstanding = DB::table('suppliers')->sum('balance');

        // Net profit = Sales Revenue - Purchases - Expenses - Losses
        $netProfit = $salesRevenue - $purchaseCosts - $totalDispenses - $totalLossValue;

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'summary' => [
                'sales_revenue' => $salesRevenue,
                'purchase_costs' => $purchaseCosts,
                'gross_profit' => $salesRevenue - $purchaseCosts,
                'total_expenses' => $totalDispenses,
                'stock_losses' => $totalLossValue,
                'net_profit' => $netProfit,
                'collections' => $collections,
                'payments_received' => $paymentsReceived,
                'payments_made' => $paymentsMade,
                'net_cash_flow' => $collections + $paymentsReceived - $paymentsMade - $totalDispenses,
            ],
            'stock_losses_detail' => [
                'count' => $stockLosses->loss_count ?? 0,
                'total_quantity' => $stockLosses->total_quantity ?? 0,
                'total_value' => $totalLossValue,
            ],
            'expenses_by_category' => $dispensesByCategory,
            'outstanding' => [
                'clients_receivable' => $clientsOutstanding,
                'suppliers_payable' => $suppliersOutstanding,
            ],
        ]);
    }

    /**
     * Client Balances Report
     */
    public function clientBalances(Request $request)
    {
        $data = Client::selectRaw('
                id,
                name,
                phone,
                address,
                balance,
                credit_limit,
                CASE WHEN balance > credit_limit THEN 1 ELSE 0 END as over_limit
            ')
            ->orderByDesc('balance')
            ->get();

        $totals = [
            'total_clients' => $data->count(),
            'total_balance' => $data->sum('balance'),
            'over_limit_count' => $data->where('over_limit', 1)->count(),
        ];

        return response()->json([
            'data' => $data,
            'totals' => $totals,
        ]);
    }

    /**
     * Collections Report - By Date
     */
    public function collectionsReport(Request $request)
    {
        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : Carbon::now()->startOfMonth();
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : Carbon::now()->endOfDay();

        // From deliveries
        $deliveryCollections = Delivery::with('livreur')
            ->whereBetween('date', [$fromDate, $toDate])
            ->where('collected_amount', '>', 0)
            ->get()
            ->map(function ($delivery) {
                return [
                    'date' => $delivery->date,
                    'type' => 'delivery',
                    'reference' => $delivery->reference,
                    'livreur' => $delivery->livreur->name ?? 'N/A',
                    'amount' => $delivery->collected_amount,
                    'expected' => $delivery->total_amount,
                ];
            });

        // Summary by livreur
        $byLivreur = Delivery::join('users', 'deliveries.livreur_id', '=', 'users.id')
            ->whereBetween('deliveries.date', [$fromDate, $toDate])
            ->selectRaw('
                users.id,
                users.name,
                SUM(deliveries.total_amount) as expected,
                SUM(deliveries.collected_amount) as collected,
                SUM(deliveries.total_amount) - SUM(deliveries.collected_amount) as pending
            ')
            ->groupBy('users.id', 'users.name')
            ->get();

        return response()->json([
            'period' => [
                'from' => $fromDate->format('Y-m-d'),
                'to' => $toDate->format('Y-m-d'),
            ],
            'collections' => $deliveryCollections,
            'by_livreur' => $byLivreur,
            'totals' => [
                'expected' => $byLivreur->sum('expected'),
                'collected' => $byLivreur->sum('collected'),
                'pending' => $byLivreur->sum('pending'),
            ],
        ]);
    }

    // ===================== DEBT REPORTS =====================

    /**
     * Debt Summary Report
     */
    public function debtSummary(Request $request)
    {
        // Total debt from all clients
        $totalDebt = Client::where('balance', '>', 0)->sum('balance');

        // Clients with debt count
        $clientsWithDebt = Client::where('balance', '>', 0)->count();

        // Over limit clients
        $overLimitClients = Client::whereColumn('balance', '>', 'credit_limit')
            ->where('balance', '>', 0)
            ->count();

        // Debt by age (from delivery orders) - Using a single optimized query
        $now = Carbon::now()->startOfDay();

        // Calculate all aging buckets in one query for better performance
        $agingData = DeliveryOrder::whereRaw('amount_due > amount_collected')
            ->whereIn('status', ['delivered', 'partial'])
            ->whereNotNull('delivered_at')
            ->selectRaw("
                SUM(CASE
                    WHEN DATEDIFF(NOW(), delivered_at) <= 7
                    THEN amount_due - amount_collected ELSE 0 END) as days_0_7,
                SUM(CASE
                    WHEN DATEDIFF(NOW(), delivered_at) > 7 AND DATEDIFF(NOW(), delivered_at) <= 30
                    THEN amount_due - amount_collected ELSE 0 END) as days_8_30,
                SUM(CASE
                    WHEN DATEDIFF(NOW(), delivered_at) > 30 AND DATEDIFF(NOW(), delivered_at) <= 60
                    THEN amount_due - amount_collected ELSE 0 END) as days_31_60,
                SUM(CASE
                    WHEN DATEDIFF(NOW(), delivered_at) > 60
                    THEN amount_due - amount_collected ELSE 0 END) as days_over_60,
                SUM(amount_due - amount_collected) as total_from_orders
            ")
            ->first();

        // Top debtors
        $topDebtors = Client::where('balance', '>', 0)
            ->select('id', 'name', 'phone', 'address', 'balance', 'credit_limit')
            ->orderByDesc('balance')
            ->limit(10)
            ->get();

        return response()->json([
            'summary' => [
                'total_debt' => $totalDebt,
                'clients_with_debt' => $clientsWithDebt,
                'over_limit_clients' => $overLimitClients,
                'average_debt' => $clientsWithDebt > 0 ? round($totalDebt / $clientsWithDebt, 2) : 0,
            ],
            'aging' => [
                'days_0_7' => (float) ($agingData->days_0_7 ?? 0),
                'days_8_30' => (float) ($agingData->days_8_30 ?? 0),
                'days_31_60' => (float) ($agingData->days_31_60 ?? 0),
                'days_over_60' => (float) ($agingData->days_over_60 ?? 0),
            ],
            'top_debtors' => $topDebtors,
        ]);
    }

    /**
     * Detailed Debt Report - All clients with debt
     */
    public function debtDetails(Request $request)
    {
        $query = Client::where('balance', '>', 0);

        if ($request->search) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->over_limit_only) {
            $query->whereColumn('balance', '>', 'credit_limit');
        }

        $sortBy = $request->sort_by ?? 'balance';
        $sortDir = $request->sort_dir ?? 'desc';

        $clients = $query->orderBy($sortBy, $sortDir)->get();

        // Get unpaid delivery orders for each client
        $clientIds = $clients->pluck('id');

        $unpaidOrders = DeliveryOrder::whereIn('client_id', $clientIds)
            ->whereRaw('amount_due > amount_collected')
            ->whereIn('status', ['delivered', 'partial'])
            ->with(['order:id,reference', 'delivery:id,reference,date'])
            ->get()
            ->groupBy('client_id');

        $now = Carbon::now();
        $data = $clients->map(function ($client) use ($unpaidOrders, $now) {
            $clientOrders = $unpaidOrders->get($client->id, collect());

            // Calculate total from unpaid orders for verification
            $calculatedDebt = $clientOrders->sum(fn($o) => $o->amount_due - $o->amount_collected);

            return [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'address' => $client->address,
                'total_debt' => $client->balance, // Use client balance as source of truth
                'calculated_debt' => $calculatedDebt, // From orders - for debugging/verification
                'credit_limit' => $client->credit_limit,
                'over_limit' => $client->balance > $client->credit_limit,
                'unpaid_orders_count' => $clientOrders->count(),
                'oldest_debt_date' => $clientOrders->min('delivered_at'),
                'unpaid_orders' => $clientOrders->map(function ($order) use ($now) {
                    $daysOld = $order->delivered_at
                        ? Carbon::parse($order->delivered_at)->diffInDays($now)
                        : null;
                    return [
                        'id' => $order->id,
                        'reference' => $order->order->reference ?? 'N/A',
                        'delivery_reference' => $order->delivery->reference ?? 'N/A',
                        'delivery_date' => $order->delivery->date ?? null,
                        'delivered_at' => $order->delivered_at
                            ? Carbon::parse($order->delivered_at)->format('Y-m-d')
                            : null,
                        'amount_due' => (float) $order->amount_due,
                        'amount_collected' => (float) $order->amount_collected,
                        'remaining' => (float) ($order->amount_due - $order->amount_collected),
                        'days_old' => $daysOld,
                    ];
                })->sortByDesc('days_old')->values(),
            ];
        });

        $totals = [
            'total_clients' => $data->count(),
            'total_debt' => $data->sum('total_debt'),
            'total_unpaid_orders' => $data->sum('unpaid_orders_count'),
            'over_limit_count' => $data->where('over_limit', true)->count(),
        ];

        return response()->json([
            'data' => $data,
            'totals' => $totals,
        ]);
    }

    /**
     * Debt by Client - Single client detailed debt history
     */
    public function debtByClient(Request $request, $clientId)
    {
        $client = Client::findOrFail($clientId);

        $fromDate = $request->from_date ? Carbon::parse($request->from_date)->startOfDay() : null;
        $toDate = $request->to_date ? Carbon::parse($request->to_date)->endOfDay() : null;

        // Get all delivery orders with outstanding debt
        $query = DeliveryOrder::where('client_id', $clientId)
            ->whereIn('status', ['delivered', 'partial'])
            ->with(['order:id,reference,created_at', 'delivery:id,reference,date,livreur_id', 'delivery.livreur:id,name']);

        if ($fromDate && $toDate) {
            $query->whereBetween('delivered_at', [$fromDate, $toDate]);
        }

        $allOrders = $query->orderByDesc('delivered_at')->get();

        // Separate paid and unpaid
        $unpaidOrders = $allOrders->filter(fn($o) => $o->amount_due > $o->amount_collected);
        $paidOrders = $allOrders->filter(fn($o) => $o->amount_due <= $o->amount_collected);

        // Calculate debt aging
        $now = Carbon::now();
        $aging = [
            'days_0_7' => 0,
            'days_8_30' => 0,
            'days_31_60' => 0,
            'days_over_60' => 0,
        ];

        foreach ($unpaidOrders as $order) {
            $days = $order->delivered_at ? Carbon::parse($order->delivered_at)->diffInDays($now) : 0;
            $debt = $order->amount_due - $order->amount_collected;

            if ($days <= 7) {
                $aging['days_0_7'] += $debt;
            } elseif ($days <= 30) {
                $aging['days_8_30'] += $debt;
            } elseif ($days <= 60) {
                $aging['days_31_60'] += $debt;
            } else {
                $aging['days_over_60'] += $debt;
            }
        }

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'phone' => $client->phone,
                'address' => $client->address,
                'balance' => $client->balance,
                'credit_limit' => $client->credit_limit,
            ],
            'summary' => [
                'total_orders' => $allOrders->count(),
                'unpaid_orders' => $unpaidOrders->count(),
                'paid_orders' => $paidOrders->count(),
                'total_debt' => $unpaidOrders->sum(fn($o) => $o->amount_due - $o->amount_collected),
                'total_collected' => $allOrders->sum('amount_collected'),
                'total_due' => $allOrders->sum('amount_due'),
            ],
            'aging' => $aging,
            'unpaid_orders' => $unpaidOrders->map(function ($order) use ($now) {
                return [
                    'id' => $order->id,
                    'order_reference' => $order->order->reference ?? 'N/A',
                    'delivery_reference' => $order->delivery->reference ?? 'N/A',
                    'delivery_date' => $order->delivery->date ?? null,
                    'livreur' => $order->delivery->livreur->name ?? 'N/A',
                    'delivered_at' => $order->delivered_at,
                    'amount_due' => $order->amount_due,
                    'amount_collected' => $order->amount_collected,
                    'remaining' => $order->amount_due - $order->amount_collected,
                    'days_old' => $order->delivered_at ? Carbon::parse($order->delivered_at)->diffInDays($now) : null,
                ];
            })->values(),
            'payment_history' => $paidOrders->map(function ($order) {
                return [
                    'order_reference' => $order->order->reference ?? 'N/A',
                    'delivery_date' => $order->delivery->date ?? null,
                    'amount_due' => $order->amount_due,
                    'amount_collected' => $order->amount_collected,
                    'delivered_at' => $order->delivered_at,
                ];
            })->values(),
        ]);
    }

    /**
     * Debt Aging Report
     */
    public function debtAging(Request $request)
    {
        $now = Carbon::now();

        // Get all unpaid delivery orders grouped by client
        $unpaidOrders = DeliveryOrder::whereRaw('amount_due > amount_collected')
            ->whereIn('status', ['delivered', 'partial'])
            ->whereNotNull('client_id')
            ->whereNotNull('delivered_at')
            ->with(['client:id,name,phone'])
            ->get();

        $clientDebts = [];

        foreach ($unpaidOrders as $order) {
            // Skip if client doesn't exist
            if (!$order->client) {
                continue;
            }

            $clientId = $order->client_id;
            $debt = (float) ($order->amount_due - $order->amount_collected);
            $days = Carbon::parse($order->delivered_at)->diffInDays($now);

            if (!isset($clientDebts[$clientId])) {
                $clientDebts[$clientId] = [
                    'id' => $clientId,
                    'name' => $order->client->name,
                    'phone' => $order->client->phone ?? '-',
                    'current' => 0,      // 0-7 days
                    'days_30' => 0,      // 8-30 days
                    'days_60' => 0,      // 31-60 days
                    'over_60' => 0,      // >60 days
                    'total' => 0,
                ];
            }

            if ($days <= 7) {
                $clientDebts[$clientId]['current'] += $debt;
            } elseif ($days <= 30) {
                $clientDebts[$clientId]['days_30'] += $debt;
            } elseif ($days <= 60) {
                $clientDebts[$clientId]['days_60'] += $debt;
            } else {
                $clientDebts[$clientId]['over_60'] += $debt;
            }
            $clientDebts[$clientId]['total'] += $debt;
        }

        $data = collect($clientDebts)->sortByDesc('total')->values();

        $totals = [
            'current' => round($data->sum('current'), 2),
            'days_30' => round($data->sum('days_30'), 2),
            'days_60' => round($data->sum('days_60'), 2),
            'over_60' => round($data->sum('over_60'), 2),
            'total' => round($data->sum('total'), 2),
        ];

        return response()->json([
            'data' => $data,
            'totals' => $totals,
        ]);
    }

    // ===================== HELPER METHODS =====================

    private function getMovementTypeLabel($type, $reference = null)
    {
        // Check if it's a loss (reference starts with LOSS-)
        if ($reference && str_starts_with($reference, 'LOSS-')) {
            return 'خسارة مخزون';
        }

        $labels = [
            'purchase' => 'شراء',
            'purchase_return' => 'مرتجع شراء',
            'sale' => 'بيع',
            'sale_return' => 'مرتجع بيع',
            'adjustment' => 'تعديل',
            'adjustment_add' => 'تعديل إضافة',
            'adjustment_sub' => 'تعديل نقص',
            'transfer' => 'تحويل',
            'transfer_in' => 'تحويل وارد',
            'transfer_out' => 'تحويل صادر',
            'delivery' => 'توصيل',
            'delivery_out' => 'خروج للتوصيل',
            'delivery_return' => 'مرتجع من التوصيل',
            'opening' => 'رصيد افتتاحي',
            'initial' => 'رصيد افتتاحي',
        ];

        return $labels[$type] ?? $type;
    }
}
