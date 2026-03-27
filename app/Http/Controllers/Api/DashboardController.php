<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Caisse;
use App\Models\Client;
use App\Models\Delivery;
use App\Models\Order;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Stock;
use App\Models\Supplier;
use App\Models\Trip;
use App\Models\User;
use App\Models\Warehouse;
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
                $q->whereRaw('quantity <= products.stock_alert * COALESCE(products.pieces_per_package, 1)');
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
            $q->whereRaw('quantity <= products.stock_alert * COALESCE(products.pieces_per_package, 1)');
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

    public function getSystemHealth()
    {
        $user = auth()->user();
        if (!$user->isAdmin()) {
            return response()->json([]);
        }

        $alerts = [];

        // 1. Users without caisse
        $usersWithoutCaisse = User::whereDoesntHave('caisse')
            ->whereIn('role', ['admin', 'seller', 'livreur', 'cashvan'])
            ->where('email', '!=', 'admin@symloop.com')
            ->select('id', 'name', 'role')
            ->get();

        if ($usersWithoutCaisse->isNotEmpty()) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'مستخدمون بدون صندوق',
                'message' => $usersWithoutCaisse->count() . ' مستخدم بدون صندوق مالي',
                'count' => $usersWithoutCaisse->count(),
                'items' => $usersWithoutCaisse,
                'action' => 'fix_caisses',
            ];
        }

        // 2. Livreur/cashvan without warehouse
        $usersWithoutWarehouse = User::whereNull('warehouse_id')
            ->whereIn('role', ['livreur', 'cashvan'])
            ->where('is_active', true)
            ->select('id', 'name', 'role')
            ->get();

        if ($usersWithoutWarehouse->isNotEmpty()) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'سائقون بدون مستودع',
                'message' => $usersWithoutWarehouse->count() . ' مستخدم بدون مستودع مخصص',
                'count' => $usersWithoutWarehouse->count(),
                'items' => $usersWithoutWarehouse,
            ];
        }

        // 3. Caisses with balance belonging to inactive users
        $orphanedCaisses = Caisse::where('balance', '>', 0)
            ->whereHas('user', fn($q) => $q->where('is_active', false))
            ->with('user:id,name')
            ->get();

        if ($orphanedCaisses->isNotEmpty()) {
            $total = $orphanedCaisses->sum('balance');
            $alerts[] = [
                'type' => 'danger',
                'title' => 'صناديق بها رصيد لمستخدمين غير نشطين',
                'message' => $orphanedCaisses->count() . ' صندوق - إجمالي: ' . number_format($total, 2) . ' د.ج',
                'count' => $orphanedCaisses->count(),
                'items' => $orphanedCaisses->map(fn($c) => [
                    'id' => $c->id,
                    'user_name' => $c->user?->name ?? 'محذوف',
                    'balance' => (float) $c->balance,
                ]),
            ];
        }

        // 4. Negative stock
        $negativeStock = Stock::where('quantity', '<', 0)
            ->with(['product:id,name', 'warehouse:id,name'])
            ->get();

        if ($negativeStock->isNotEmpty()) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'مخزون سالب',
                'message' => $negativeStock->count() . ' منتج بمخزون سالب',
                'count' => $negativeStock->count(),
                'items' => $negativeStock->take(10)->map(fn($s) => [
                    'product' => $s->product?->name,
                    'warehouse' => $s->warehouse?->name,
                    'quantity' => $s->quantity,
                ]),
            ];
        }

        // 5. Clients with debt
        $debtorCount = Client::where('balance', '<', 0)->count();
        if ($debtorCount > 0) {
            $totalDebt = Client::where('balance', '<', 0)->sum('balance');
            $alerts[] = [
                'type' => 'info',
                'title' => 'عملاء مدينون',
                'message' => $debtorCount . ' عميل - إجمالي الديون: ' . number_format(abs($totalDebt), 2) . ' د.ج',
                'count' => $debtorCount,
            ];
        }

        // 6. Stale pending orders (>3 days)
        $staleOrders = Order::where('status', 'pending')
            ->where('date', '<', now()->subDays(3)->toDateString())
            ->count();

        if ($staleOrders > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'طلبات معلقة لأكثر من 3 أيام',
                'message' => $staleOrders . ' طلب معلق يحتاج مراجعة',
                'count' => $staleOrders,
            ];
        }

        // 7. Active products with zero stock
        $zeroStockProducts = Product::where('is_active', true)
            ->whereDoesntHave('stock', fn($q) => $q->where('quantity', '>', 0))
            ->count();

        if ($zeroStockProducts > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'منتجات بدون مخزون',
                'message' => $zeroStockProducts . ' منتج نشط بدون أي مخزون',
                'count' => $zeroStockProducts,
            ];
        }

        // 8. Non-main warehouses without assigned user
        $unassignedWarehouses = Warehouse::where('is_active', true)
            ->where('is_main', false)
            ->whereDoesntHave('assignedUser')
            ->count();

        if ($unassignedWarehouses > 0) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'مستودعات بدون مستخدم',
                'message' => $unassignedWarehouses . ' مستودع فرعي غير مخصص',
                'count' => $unassignedWarehouses,
            ];
        }

        return response()->json($alerts);
    }

    public function fixMissingCaisses()
    {
        $user = auth()->user();
        if (!$user->isAdmin()) {
            return response()->json(['message' => 'غير مصرح'], 403);
        }

        $typeMap = [
            'seller' => 'vendeur',
            'livreur' => 'livreur',
            'cashvan' => 'cashvan',
            'admin' => 'principale',
        ];

        $usersWithoutCaisse = User::whereDoesntHave('caisse')
            ->whereIn('role', array_keys($typeMap))
            ->get();

        $fixed = 0;
        foreach ($usersWithoutCaisse as $u) {
            Caisse::create([
                'user_id' => $u->id,
                'type' => $typeMap[$u->role],
            ]);
            $fixed++;
        }

        return response()->json([
            'message' => "تم إنشاء {$fixed} صندوق",
            'fixed' => $fixed,
        ]);
    }
}
