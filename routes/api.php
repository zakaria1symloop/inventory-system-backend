<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\UnitController;
use App\Http\Controllers\Api\WarehouseController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\TripController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\AdjustmentController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SyncController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\StockMovementController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\LocationController;
use App\Http\Controllers\Api\SettingController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\DispenseController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\PurchaseOrderController;

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/public/branding', [SettingController::class, 'getPublicBranding']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    // Auth
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/change-password', [AuthController::class, 'changePassword']);

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/sales-chart', [DashboardController::class, 'getSalesChart']);
    Route::get('/dashboard/top-products', [DashboardController::class, 'getTopProducts']);
    Route::get('/dashboard/top-clients', [DashboardController::class, 'getTopClients']);
    Route::get('/dashboard/low-stock', [DashboardController::class, 'getLowStock']);
    Route::get('/dashboard/seller-stats', [DashboardController::class, 'getSellerStats']);
    Route::get('/dashboard/livreur-stats', [DashboardController::class, 'getLivreurStats']);

    // Users
    Route::apiResource('users', UserController::class);
    Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
    Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
    Route::get('/sellers', [UserController::class, 'getSellers']);
    Route::get('/livreurs', [UserController::class, 'getLivreurs']);

    // Categories
    Route::apiResource('categories', CategoryController::class);

    // Brands
    Route::apiResource('brands', BrandController::class);

    // Units
    Route::apiResource('units', UnitController::class);
    Route::post('/units/convert', [UnitController::class, 'convert']);

    // Warehouses
    Route::apiResource('warehouses', WarehouseController::class);
    Route::get('/warehouses/{warehouse}/stock', [WarehouseController::class, 'getStock']);

    // Products - specific routes MUST come before apiResource
    Route::get('/products/generate-barcode', [ProductController::class, 'generateBarcode']);
    Route::get('/products/available-stock/bulk', [ProductController::class, 'getAvailableStockBulk']);
    Route::post('/products/find-by-barcode', [ProductController::class, 'findByBarcode']);
    Route::apiResource('products', ProductController::class);
    Route::get('/products/{product}/stock', [ProductController::class, 'getStock']);
    Route::get('/products/{product}/available-stock', [ProductController::class, 'getAvailableStock']);

    // Vehicles
    Route::apiResource('vehicles', VehicleController::class);

    // Clients
    Route::apiResource('clients', ClientController::class);
    Route::get('/clients/{client}/balance', [ClientController::class, 'getBalance']);
    Route::get('/clients/{client}/orders', [ClientController::class, 'getOrders']);
    Route::get('/clients/{client}/sales', [ClientController::class, 'getSales']);
    Route::get('/clients/{client}/sales-debt', [ClientController::class, 'getSalesDebt']);

    // Suppliers
    Route::apiResource('suppliers', SupplierController::class);
    Route::get('/suppliers/{supplier}/balance', [SupplierController::class, 'getBalance']);
    Route::get('/suppliers/{supplier}/purchases', [SupplierController::class, 'getPurchases']);

    // Purchases
    Route::apiResource('purchases', PurchaseController::class);
    Route::post('/purchases/{purchase}/return', [PurchaseController::class, 'createReturn']);
    Route::get('/purchases/{purchase}/facture/pdf', [PurchaseController::class, 'generateFacturePdf']);
    Route::get('/purchases/{purchase}/facture/stream', [PurchaseController::class, 'streamFacturePdf']);
    Route::get('/purchases/{purchase}/bon-commande/pdf', [PurchaseController::class, 'generateBonCommandePdf']);
    Route::get('/purchases/{purchase}/bon-commande/stream', [PurchaseController::class, 'streamBonCommandePdf']);

    // Purchase Orders (Bons de Commande - no stock effect)
    Route::apiResource('purchase-orders', PurchaseOrderController::class);
    Route::put('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus']);
    Route::post('/purchase-orders/{purchaseOrder}/convert', [PurchaseOrderController::class, 'convertToPurchase']);
    Route::get('/purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'generatePdf']);
    Route::get('/purchase-orders/{purchaseOrder}/stream', [PurchaseOrderController::class, 'streamPdf']);

    // Sales
    Route::apiResource('sales', SaleController::class);
    Route::post('/sales/{sale}/return', [SaleController::class, 'createReturn']);
    Route::get('/sales/{sale}/facture/pdf', [SaleController::class, 'generateFacturePdf']);
    Route::get('/sales/{sale}/facture/stream', [SaleController::class, 'streamFacturePdf']);
    Route::get('/sales/{sale}/bon-livraison/pdf', [SaleController::class, 'generateBonLivraisonPdf']);
    Route::get('/sales/{sale}/bon-livraison/stream', [SaleController::class, 'streamBonLivraisonPdf']);

    // Stock Movements & Returns
    Route::get('/stock-movements', [StockMovementController::class, 'index']);
    Route::get('/stock-movements/product/{productId}', [StockMovementController::class, 'getByProduct']);
    Route::get('/stock-movements/summary', [StockMovementController::class, 'getSummary']);
    Route::get('/purchase-returns', [StockMovementController::class, 'purchaseReturns']);
    Route::get('/purchase-returns/{id}', [StockMovementController::class, 'purchaseReturnShow']);
    Route::get('/sale-returns', [StockMovementController::class, 'saleReturns']);
    Route::get('/sale-returns/{id}', [StockMovementController::class, 'saleReturnShow']);

    // Payments
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
    Route::post('/purchases/{purchase}/payments', [PaymentController::class, 'storePurchasePayment']);
    Route::post('/sales/{sale}/payments', [PaymentController::class, 'storeSalePayment']);

    // Adjustments
    Route::apiResource('adjustments', AdjustmentController::class)->except(['update']);
    Route::post('/adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve']);
    Route::post('/adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject']);

    // Inventory Management
    Route::get('/inventory', [InventoryController::class, 'index']);
    Route::get('/inventory/warehouse/{warehouseId}', [InventoryController::class, 'getByWarehouse']);
    Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/count', [InventoryController::class, 'count']);
    Route::post('/inventory/transfer', [InventoryController::class, 'transfer']);

    // Trips (Seller)
    Route::apiResource('trips', TripController::class)->except(['update', 'destroy']);
    Route::post('/trips/{trip}/stores', [TripController::class, 'addStore']);
    Route::post('/trips/{trip}/stores/{store}/visit', [TripController::class, 'visitStore']);
    Route::post('/trips/{trip}/stores/{store}/skip', [TripController::class, 'skipStore']);
    Route::post('/trips/{trip}/complete', [TripController::class, 'complete']);
    Route::post('/trips/{trip}/cancel', [TripController::class, 'cancel']);
    Route::get('/my-active-trip', [TripController::class, 'getMyActiveTrip']);
    Route::get('/my-trips', [TripController::class, 'getMyTrips']);

    // Orders
    Route::apiResource('orders', OrderController::class);
    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::put('/orders/{order}/items/{item}', [OrderController::class, 'updateItemQuantity']);
    Route::get('/orders/{order}/pdf', [OrderController::class, 'generatePdf']);
    Route::get('/orders/{order}/pdf/stream', [OrderController::class, 'streamPdf']);
    Route::post('/orders/{order}/report-problem', [OrderController::class, 'reportProblem']);
    Route::post('/orders/{order}/resolve-problem', [OrderController::class, 'resolveProblem']);
    Route::get('/pending-orders', [OrderController::class, 'getPendingOrders']);
    Route::get('/confirmed-orders', [OrderController::class, 'getConfirmedOrders']);
    Route::get('/unassigned-orders', [OrderController::class, 'getUnassignedOrders']);
    Route::get('/orders-with-problems', [OrderController::class, 'getOrdersWithProblems']);
    Route::get('/my-orders', [OrderController::class, 'getMyOrders']);

    // Deliveries (Livreur)
    Route::apiResource('deliveries', DeliveryController::class)->except(['update', 'destroy']);
    Route::post('/deliveries/{delivery}/start', [DeliveryController::class, 'start']);
    Route::post('/deliveries/{delivery}/complete', [DeliveryController::class, 'complete']);
    Route::post('/deliveries/{delivery}/orders/{deliveryOrder}/deliver', [DeliveryController::class, 'deliverOrder']);
    Route::post('/deliveries/{delivery}/orders/{deliveryOrder}/partial', [DeliveryController::class, 'partialDelivery']);
    Route::post('/deliveries/{delivery}/orders/{deliveryOrder}/fail', [DeliveryController::class, 'failOrder']);
    Route::post('/deliveries/{delivery}/orders/{deliveryOrder}/postpone', [DeliveryController::class, 'postponeOrder']);
    Route::post('/deliveries/{delivery}/orders/{deliveryOrder}/collect-payment', [DeliveryController::class, 'collectPayment']);
    Route::post('/deliveries/{delivery}/process-returns', [DeliveryController::class, 'processReturns']);
    Route::post('/deliveries/{delivery}/returns/{return}/process', [DeliveryController::class, 'processReturn']);
    Route::get('/my-active-delivery', [DeliveryController::class, 'getMyActiveDelivery']);
    Route::get('/my-deliveries', [DeliveryController::class, 'getMyDeliveries']);
    Route::get('/deliveries/{delivery}/orders/{deliveryOrder}/items', [DeliveryController::class, 'getDeliveryOrderItems']);

    // Debtors (Clients with outstanding payments)
    Route::get('/debtors', [DeliveryController::class, 'getDebtors']);
    Route::get('/debtors/{clientId}', [DeliveryController::class, 'getClientDebt']);

    // All Debtors (Combined: Sales + Deliveries)
    Route::get('/all-debtors', [DeliveryController::class, 'getAllDebtors']);
    Route::get('/all-debtors/{clientId}', [DeliveryController::class, 'getAllClientDebt']);

    // Creditors (Suppliers we owe money to)
    Route::get('/creditors', [SupplierController::class, 'getCreditors']);
    Route::get('/creditors/{supplierId}', [SupplierController::class, 'getSupplierDebt']);

    // Sync (Mobile)
    Route::get('/sync/master-data', [SyncController::class, 'getMasterData']);
    Route::post('/sync/push', [SyncController::class, 'pushChanges']);
    Route::get('/sync/logs', [SyncController::class, 'getSyncLogs']);

    // Reports
    Route::prefix('reports')->group(function () {
        // Sales Reports
        Route::get('/sales/summary', [ReportController::class, 'salesSummary']);
        Route::get('/sales/by-product', [ReportController::class, 'salesByProduct']);
        Route::get('/sales/by-client', [ReportController::class, 'salesByClient']);
        Route::get('/sales/by-seller', [ReportController::class, 'salesBySeller']);

        // Delivery Reports
        Route::get('/delivery/summary', [ReportController::class, 'deliverySummary']);
        Route::get('/delivery/by-livreur', [ReportController::class, 'deliveryByLivreur']);
        Route::get('/delivery/details', [ReportController::class, 'deliveryDetails']);

        // Stock Reports
        Route::get('/stock/summary', [ReportController::class, 'stockSummary']);
        Route::get('/stock/movements', [ReportController::class, 'stockMovements']);
        Route::get('/stock/low-stock', [ReportController::class, 'lowStockAlert']);

        // Financial Reports
        Route::get('/financial/summary', [ReportController::class, 'financialSummary']);
        Route::get('/financial/client-balances', [ReportController::class, 'clientBalances']);
        Route::get('/financial/collections', [ReportController::class, 'collectionsReport']);

        // Debt Reports
        Route::get('/debt/summary', [ReportController::class, 'debtSummary']);
        Route::get('/debt/details', [ReportController::class, 'debtDetails']);
        Route::get('/debt/aging', [ReportController::class, 'debtAging']);
        Route::get('/debt/client/{clientId}', [ReportController::class, 'debtByClient']);
    });

    // Location Tracking
    Route::post('/location/update', [LocationController::class, 'updateLocation']);
    Route::get('/location/drivers', [LocationController::class, 'getAllDrivers']);
    Route::get('/location/drivers/active', [LocationController::class, 'getActiveDrivers']);

    // Settings
    Route::get('/settings', [SettingController::class, 'index']);
    Route::get('/settings/group/{group}', [SettingController::class, 'getByGroup']);
    Route::put('/settings', [SettingController::class, 'update']);
    Route::post('/settings/logo', [SettingController::class, 'uploadLogo']);
    Route::delete('/settings/logo', [SettingController::class, 'deleteLogo']);
    Route::get('/settings/company-info', [SettingController::class, 'getCompanyInfo']);

    // Settings Password Protection
    Route::get('/settings/has-password', [SettingController::class, 'hasPassword']);
    Route::post('/settings/verify-password', [SettingController::class, 'verifyPassword']);
    Route::post('/settings/set-password', [SettingController::class, 'setPassword']);
    Route::post('/settings/remove-password', [SettingController::class, 'removePassword']);

    // Employees
    Route::get('/employees', [EmployeeController::class, 'index']);
    Route::post('/employees', [EmployeeController::class, 'store']);
    Route::get('/employees/active', [EmployeeController::class, 'getActive']);
    Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
    Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
    Route::post('/employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive']);

    // Dispenses (Expenses)
    Route::get('/dispenses', [DispenseController::class, 'index']);
    Route::post('/dispenses', [DispenseController::class, 'store']);
    Route::get('/dispenses/categories', [DispenseController::class, 'getCategories']);
    Route::get('/dispenses/summary', [DispenseController::class, 'summary']);
    Route::get('/dispenses/{dispense}', [DispenseController::class, 'show']);
    Route::put('/dispenses/{dispense}', [DispenseController::class, 'update']);
    Route::delete('/dispenses/{dispense}', [DispenseController::class, 'destroy']);
});
