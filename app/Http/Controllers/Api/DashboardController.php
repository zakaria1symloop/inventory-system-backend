<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $today = now()->toDateString();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        return response()->json([
            'stats' => [
                'total_products' => Product::count(),
                'total_clients' => Client::count(),
                'total_suppliers' => Supplier::count(),
                'low_stock_count' => $this->getLowStockCount(),
            ],
            'today' => [
                'sales' => Sale::whereDate('date', $today)->sum('grand_total'),
                'purchases' => Purchase::whereDate('date', $today)->sum('grand_total'),
                'orders' => Order::whereDate('date', $today)->count(),
                'deliveries' => Delivery::whereDate('date', $today)->count(),
            ],
            'monthly' => [
                'sales' => Sale::whereBetween('date', [$startOfMonth, $endOfMonth])->sum('grand_total'),
                'purchases' => Purchase::whereBetween('date', [$startOfMonth, $endOfMonth])->sum('grand_total'),
                'orders' => Order::whereBetween('date', [$startOfMonth, $endOfMonth])->count(),
            ],
            'pending' => [
                'orders' => Order::pending()->count(),
                'deliveries' => Delivery::whereIn('status', ['preparing', 'in_progress'])->count(),
                'purchase_returns' => \App\Models\PurchaseReturn::where('status', 'pending')->count(),
                'sale_returns' => \App\Models\SaleReturn::where('status', 'pending')->count(),
            ],
            'recent_orders' => Order::with(['client', 'seller'])
                ->latest()
                ->take(5)
                ->get(),
            'recent_sales' => Sale::with(['client', 'user'])
                ->latest()
                ->take(5)
                ->get(),
        ]);
    }

    public function getSalesChart(Request $request)
    {
        $days = $request->days ?? 30;
        $startDate = now()->subDays($days)->toDateString();

        $sales = Sale::select(
                DB::raw('DATE(date) as date'),
                DB::raw('SUM(grand_total) as total')
            )
            ->where('date', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return response()->json($sales);
    }

    public function getTopProducts(Request $request)
    {
        $limit = $request->limit ?? 10;
        $startDate = $request->from_date ?? now()->startOfMonth()->toDateString();
        $endDate = $request->to_date ?? now()->toDateString();

        $products = DB::table('sale_items')
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->join('products', 'sale_items.product_id', '=', 'products.id')
            ->select(
                'products.id',
                'products.name',
                DB::raw('SUM(sale_items.quantity) as total_quantity'),
                DB::raw('SUM(sale_items.subtotal) as total_amount')
            )
            ->whereBetween('sales.date', [$startDate, $endDate])
            ->whereNull('sales.deleted_at')
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('total_quantity')
            ->limit($limit)
            ->get();

        return response()->json($products);
    }

    public function getTopClients(Request $request)
    {
        $limit = $request->limit ?? 10;
        $startDate = $request->from_date ?? now()->startOfMonth()->toDateString();
        $endDate = $request->to_date ?? now()->toDateString();

        $clients = DB::table('sales')
            ->join('clients', 'sales.client_id', '=', 'clients.id')
            ->select(
                'clients.id',
                'clients.name',
                DB::raw('COUNT(sales.id) as total_orders'),
                DB::raw('SUM(sales.grand_total) as total_amount')
            )
            ->whereBetween('sales.date', [$startDate, $endDate])
            ->whereNull('sales.deleted_at')
            ->groupBy('clients.id', 'clients.name')
            ->orderByDesc('total_amount')
            ->limit($limit)
            ->get();

        return response()->json($clients);
    }

    public function getLowStock(Request $request)
    {
        $products = Product::with(['stock.warehouse'])
            ->whereHas('stock', function ($q) {
                $q->whereRaw('quantity <= products.stock_alert');
            })
            ->get()
            ->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'barcode' => $product->barcode,
                    'stock_alert' => $product->stock_alert,
                    'current_stock' => $product->getTotalStock(),
                    'stock_by_warehouse' => $product->stock->map(function ($s) {
                        return [
                            'warehouse' => $s->warehouse->name,
                            'quantity' => $s->quantity,
                        ];
                    }),
                ];
            });

        return response()->json($products);
    }

    private function getLowStockCount()
    {
        return Product::whereHas('stock', function ($q) {
            $q->whereRaw('quantity <= products.stock_alert');
        })->count();
    }

    public function getSellerStats(Request $request)
    {
        $sellerId = $request->seller_id ?? auth()->id();
        $startDate = $request->from_date ?? now()->startOfMonth()->toDateString();
        $endDate = $request->to_date ?? now()->toDateString();

        return response()->json([
            'trips' => Trip::where('seller_id', $sellerId)
                ->whereBetween('created_at', [$startDate, $endDate])
                ->count(),
            'orders' => Order::where('seller_id', $sellerId)
                ->whereBetween('date', [$startDate, $endDate])
                ->count(),
            'total_amount' => Order::where('seller_id', $sellerId)
                ->whereBetween('date', [$startDate, $endDate])
                ->sum('grand_total'),
            'clients_visited' => Order::where('seller_id', $sellerId)
                ->whereBetween('date', [$startDate, $endDate])
                ->distinct('client_id')
                ->count('client_id'),
        ]);
    }

    public function getLivreurStats(Request $request)
    {
        $livreurId = $request->livreur_id ?? auth()->id();
        $startDate = $request->from_date ?? now()->startOfMonth()->toDateString();
        $endDate = $request->to_date ?? now()->toDateString();

        $deliveries = Delivery::where('livreur_id', $livreurId)
            ->whereBetween('date', [$startDate, $endDate]);

        return response()->json([
            'total_deliveries' => $deliveries->count(),
            'completed' => (clone $deliveries)->where('status', 'completed')->count(),
            'total_orders' => $deliveries->sum('total_orders'),
            'delivered' => $deliveries->sum('delivered_count'),
            'failed' => $deliveries->sum('failed_count'),
            'delivery_rate' => $this->calculateDeliveryRate($deliveries),
        ]);
    }

    private function calculateDeliveryRate($deliveriesQuery)
    {
        $total = $deliveriesQuery->sum('total_orders');
        $delivered = $deliveriesQuery->sum('delivered_count');

        return $total > 0 ? round(($delivered / $total) * 100, 2) : 0;
    }
}
