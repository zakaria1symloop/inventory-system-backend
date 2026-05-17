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
use App\Http\Controllers\Api\ClientCategoryController;
use App\Http\Controllers\Api\CaisseController;
use App\Http\Controllers\Api\VanSessionController;
use App\Http\Controllers\Api\ProductRequestController;
use App\Http\Controllers\Api\StockTransferController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\SaasAuthController;
use App\Http\Controllers\Api\SaasPlanController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\SaasPaymentController;
use App\Http\Controllers\Api\Admin\AdminAuthController;
use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AdminTenantController;
use App\Http\Controllers\Api\Admin\AdminContactMessageController;
use App\Http\Controllers\Api\Admin\AdminSubscriptionController;
use App\Http\Controllers\Api\Admin\AdminPaymentController;
use App\Http\Controllers\Api\Admin\AdminSettingsController;
use App\Http\Controllers\Api\Admin\AdminVersionController;

// SaaS Public Routes
Route::post('/mobile/login', [SaasAuthController::class, 'mobileLogin']);

Route::prefix('saas')->group(function () {
    Route::post('/register', [SaasAuthController::class, 'register']);
    Route::post('/login', [SaasAuthController::class, 'login']);
    Route::get('/plans', [SaasPlanController::class, 'index']);
    Route::post('/forgot-password', [SaasAuthController::class, 'forgotPassword']);
    Route::post('/reset-password', [SaasAuthController::class, 'resetPassword']);
    Route::post('/google-auth', [SaasAuthController::class, 'googleAuth']);
    Route::post('/send-verification-otp', [SaasAuthController::class, 'sendVerificationOtp']);
    Route::post('/verify-email', [SaasAuthController::class, 'verifyEmail']);
});

// SlickPay Webhook (public, no auth)
Route::post('/saas/payments/webhook', [SaasPaymentController::class, 'webhook']);

// Contact Form (public)
Route::post('/contact', [ContactController::class, 'store']);

// Admin Auth (public)
Route::post('/admin/login', [AdminAuthController::class, 'login']);

// Admin Protected Routes
Route::middleware(['auth:sanctum', 'admin', \Illuminate\Routing\Middleware\SubstituteBindings::class])->prefix('admin')->group(function () {
    Route::get('/me', [AdminAuthController::class, 'me']);
    Route::post('/logout', [AdminAuthController::class, 'logout']);
    Route::get('/dashboard', [AdminDashboardController::class, 'index']);

    // Tenants
    Route::get('/tenants', [AdminTenantController::class, 'index']);
    Route::get('/tenants/{id}', [AdminTenantController::class, 'show']);
    Route::put('/tenants/{id}/plan', [AdminTenantController::class, 'updatePlan']);
    Route::post('/tenants/{id}/toggle-active', [AdminTenantController::class, 'toggleActive']);
    Route::post('/tenants/{id}/update-status', [AdminTenantController::class, 'updateStatus']);
    Route::get('/tenants/{id}/product-count', [AdminTenantController::class, 'productCount']);
    Route::post('/tenants/{id}/grant-full-access', [AdminTenantController::class, 'grantFullAccess']);
    Route::post('/tenants/{id}/toggle-updates', [AdminTenantController::class, 'toggleUpdates']);
    Route::post('/tenants/{id}/push-update', [AdminTenantController::class, 'pushUpdate']);
    Route::get('/tenants/{id}/update-logs', [AdminTenantController::class, 'getUpdateLogs']);
    Route::post('/tenants/bulk-update', [AdminTenantController::class, 'pushBulkUpdate']);
    Route::get('/tenants/{id}/features', [AdminTenantController::class, 'getFeatures']);
    Route::put('/tenants/{id}/features', [AdminTenantController::class, 'updateFeatures']);
    Route::post('/tenants/{id}/reset-features', [AdminTenantController::class, 'resetFeatures']);
    Route::post('/tenants/{id}/users', [AdminTenantController::class, 'createUser']);
    Route::post('/tenants/{id}/impersonate', [AdminTenantController::class, 'impersonate']);
    Route::post('/tenants/{id}/users/{userId}/toggle-verification', [AdminTenantController::class, 'toggleUserVerification']);
    Route::post('/tenants/{id}/toggle-otp', [AdminTenantController::class, 'toggleOtpRequired']);
    Route::get('/tenants/{id}/export-sql', [AdminTenantController::class, 'exportSql']);
    Route::delete('/tenants/{id}', [AdminTenantController::class, 'destroy']);
    Route::post('/tenants/migrate-all', [AdminTenantController::class, 'migrateAll']);
    Route::get('/debug/db-state', [AdminTenantController::class, 'debugDbState']);

    // Subscriptions
    Route::get('/subscriptions', [AdminSubscriptionController::class, 'index']);

    // Payments
    Route::get('/payments', [AdminPaymentController::class, 'index']);

    // Settings — Admin Accounts
    Route::get('/settings/admins', [AdminSettingsController::class, 'listAdmins']);
    Route::post('/settings/admins', [AdminSettingsController::class, 'createAdmin']);
    Route::post('/settings/admins/{id}/toggle-active', [AdminSettingsController::class, 'toggleAdminActive']);
    Route::delete('/settings/admins/{id}', [AdminSettingsController::class, 'deleteAdmin']);

    // APK Management
    Route::get('/apks', [AdminVersionController::class, 'getApks']);
    Route::post('/apks/upload', [AdminVersionController::class, 'uploadApk']);
    Route::post('/apks/delete', [AdminVersionController::class, 'deleteApk']);

    // Contact Messages
    Route::get('/contact-messages', [AdminContactMessageController::class, 'index']);
    Route::get('/contact-messages/{id}', [AdminContactMessageController::class, 'show']);
    Route::post('/contact-messages/{id}/read', [AdminContactMessageController::class, 'markAsRead']);
    Route::delete('/contact-messages/{id}', [AdminContactMessageController::class, 'destroy']);
});

// Public routes
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);
Route::get('/public/branding', [SettingController::class, 'getPublicBranding']);

// Protected routes (SubstituteBindings AFTER tenant to resolve models from tenant DB)
Route::middleware(['tenant', 'auth:sanctum', \Illuminate\Routing\Middleware\SubstituteBindings::class])->group(function () {
    // Auth & Profile (no email verification required)
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'user']);
    Route::put('/profile', [AuthController::class, 'updateProfile']);
    Route::put('/change-password', [AuthController::class, 'changePassword']);

    // SaaS Payments (plan upgrade — no verification required)
    Route::post('/saas/payments/upgrade', [SaasPaymentController::class, 'upgrade']);
    Route::get('/saas/payments/{paymentId}/status', [SaasPaymentController::class, 'status']);
    Route::get('/saas/payments/history', [SaasPaymentController::class, 'history']);

    // Tenant Apps (Flutter APK download URLs)
    Route::get('/tenant/apps', function () {
        $apks = \App\Models\AppVersion::first();

        return response()->json([
            'driver_apk_url' => $apks?->driver_apk_url,
            'driver_apk_version' => $apks?->driver_apk_version,
            'sales_apk_url' => $apks?->sales_apk_url,
            'sales_apk_version' => $apks?->sales_apk_version,
            'cashvan_apk_url' => $apks?->cashvan_apk_url,
            'cashvan_apk_version' => $apks?->cashvan_apk_version,
        ]);
    });

    // Tenant Plan Info (no verification required)
    Route::get('/tenant/plan', function (\Illuminate\Http\Request $request) {
        $tenant = $request->attributes->get('tenant');
        if (!$tenant) {
            return response()->json(['plan' => 'free', 'product_limit' => 25, 'user_limit' => 1, 'product_count' => 0, 'user_count' => 0, 'trial_ends_at' => null]);
        }
        $productCount = \App\Models\Product::count();
        $userCount = \App\Models\User::count();
        $prices = \App\Models\Tenant::planPrices();
        $limits = \App\Models\Tenant::planLimits();
        $extraUserPrices = \App\Models\Tenant::extraUserPrice();

        $planNames = [
            'free' => 'مجاني',
            'starter' => 'المبتدئ',
            'pro' => 'المحترف',
            'business' => 'الأعمال',
        ];

        return response()->json([
            'tenant_name' => $tenant->name,
            'plan' => $tenant->plan,
            'plan_name' => $planNames[$tenant->plan] ?? $tenant->plan,
            'product_limit' => $tenant->product_limit,
            'product_count' => $productCount,
            'user_limit' => $tenant->user_limit,
            'user_count' => $userCount,
            'extra_user_price' => $extraUserPrices[$tenant->plan] ?? 0,
            'price' => $prices[$tenant->plan] ?? 0,
            'is_active' => $tenant->is_active,
            'trial_ends_at' => $tenant->trial_ends_at?->toISOString(),
            'features' => $tenant->getEnabledFeatures(),
            'app_version' => $tenant->app_version ?? '1.0',
            'plans' => collect($limits)->map(fn($limit, $key) => [
                'id' => $key,
                'name' => $planNames[$key] ?? $key,
                'product_limit' => $limit['products'],
                'user_limit' => $limit['users'],
                'price' => $prices[$key] ?? 0,
                'extra_user_price' => $extraUserPrices[$key] ?? 0,
            ])->values(),
        ]);
    });

    // Business routes (require email verification)
    Route::middleware('verified.email')->group(function () {

    // Dashboard
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/dashboard/sales-chart', [DashboardController::class, 'getSalesChart']);
    Route::get('/dashboard/top-products', [DashboardController::class, 'getTopProducts']);
    Route::get('/dashboard/top-clients', [DashboardController::class, 'getTopClients']);
    Route::get('/dashboard/low-stock', [DashboardController::class, 'getLowStock']);
    Route::get('/dashboard/seller-stats', [DashboardController::class, 'getSellerStats']);
    Route::get('/dashboard/livreur-stats', [DashboardController::class, 'getLivreurStats']);
    Route::get('/dashboard/system-health', [DashboardController::class, 'getSystemHealth']);
    Route::post('/dashboard/fix-caisses', [DashboardController::class, 'fixMissingCaisses']);

    // Users
    Route::middleware('check.feature:users')->group(function () {
        Route::apiResource('users', UserController::class);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
        Route::post('/users/{user}/toggle-collect-debt', [UserController::class, 'toggleCollectDebt']);
        Route::post('/users/{user}/toggle-sell-from-main-stock', [UserController::class, 'toggleSellFromMainStock']);
    });
    Route::get('/sellers', [UserController::class, 'getSellers']);
    Route::get('/livreurs', [UserController::class, 'getLivreurs']);

    // Categories
    Route::middleware('check.feature:categories')->group(function () {
        Route::apiResource('categories', CategoryController::class);
    });

    // Brands
    Route::middleware('check.feature:brands')->group(function () {
        Route::apiResource('brands', BrandController::class);
    });

    // Units
    Route::middleware('check.feature:units')->group(function () {
        Route::apiResource('units', UnitController::class);
        Route::post('/units/convert', [UnitController::class, 'convert']);
    });

    // Warehouses
    Route::middleware('check.feature:warehouses')->group(function () {
        Route::post('/warehouses/{warehouse}/assign', [WarehouseController::class, 'assignUser']);
        Route::get('/warehouses/{warehouse}/stock', [WarehouseController::class, 'getStock']);
        Route::apiResource('warehouses', WarehouseController::class);
    });

    // Products
    Route::middleware('check.feature:products')->group(function () {
        Route::get('/products/generate-barcode', [ProductController::class, 'generateBarcode']);
        Route::get('/products/available-stock/bulk', [ProductController::class, 'getAvailableStockBulk']);
        Route::get('/products/prices-for-client', [ProductController::class, 'getPricesForClient']);
        Route::post('/products/find-by-barcode', [ProductController::class, 'findByBarcode']);
        Route::get('/products/import-template', [ProductController::class, 'downloadTemplate']);
        Route::post('/products/import/preview', [ProductController::class, 'previewImport']);
        Route::post('/products/import/confirm', [ProductController::class, 'confirmImport']);
        Route::apiResource('products', ProductController::class);
        Route::get('/products/{product}/stock', [ProductController::class, 'getStock']);
        Route::get('/products/{product}/available-stock', [ProductController::class, 'getAvailableStock']);
    });

    // Vehicles
    Route::middleware('check.feature:vehicles')->group(function () {
        Route::apiResource('vehicles', VehicleController::class);
    });

    // Client Categories
    Route::middleware('check.feature:client_categories')->group(function () {
        Route::apiResource('client-categories', ClientCategoryController::class);
    });

    // Clients
    Route::middleware('check.feature:clients')->group(function () {
        Route::post('/clients/transfer-warehouse', [ClientController::class, 'transferToWarehouse']);
        Route::post('/clients/copy-warehouse', [ClientController::class, 'copyToWarehouse']);
        Route::apiResource('clients', ClientController::class);
        Route::get('/clients/{client}/balance', [ClientController::class, 'getBalance']);
        Route::get('/clients/{client}/orders', [ClientController::class, 'getOrders']);
        Route::get('/clients/{client}/sales', [ClientController::class, 'getSales']);
        Route::get('/clients/{client}/sales-debt', [ClientController::class, 'getSalesDebt']);
        Route::delete('/clients/{client}/cancel-copy', [ClientController::class, 'cancelCopy']);
        Route::post('/clients/{client}/remove-copy-flag', [ClientController::class, 'removeCopyFlag']);
        Route::get('/clients/{client}/statement', [ClientController::class, 'getStatement']);
        Route::get('/clients/{client}/statement/pdf', [ClientController::class, 'generateStatementPdf']);
    });

    // Suppliers
    Route::middleware('check.feature:suppliers')->group(function () {
        Route::apiResource('suppliers', SupplierController::class);
        Route::get('/suppliers/{supplier}/balance', [SupplierController::class, 'getBalance']);
        Route::get('/suppliers/{supplier}/purchases', [SupplierController::class, 'getPurchases']);
        Route::get('/creditors', [SupplierController::class, 'getCreditors']);
        Route::get('/creditors/{supplierId}', [SupplierController::class, 'getSupplierDebt']);
    });

    // Purchases
    Route::middleware('check.feature:purchases')->group(function () {
        Route::apiResource('purchases', PurchaseController::class);
        Route::post('/purchases/{purchase}/return', [PurchaseController::class, 'createReturn']);
        Route::post('/purchases/{purchase}/confirm', [PurchaseController::class, 'confirm']);
        Route::get('/purchases/{purchase}/facture/pdf', [PurchaseController::class, 'generateFacturePdf']);
        Route::get('/purchases/{purchase}/facture/stream', [PurchaseController::class, 'streamFacturePdf']);
        Route::get('/purchases/{purchase}/bon-commande/pdf', [PurchaseController::class, 'generateBonCommandePdf']);
        Route::get('/purchases/{purchase}/bon-commande/stream', [PurchaseController::class, 'streamBonCommandePdf']);
    });

    // Purchase Orders
    Route::middleware('check.feature:purchase_orders')->group(function () {
        Route::apiResource('purchase-orders', PurchaseOrderController::class);
        Route::put('/purchase-orders/{purchaseOrder}/status', [PurchaseOrderController::class, 'updateStatus']);
        Route::post('/purchase-orders/{purchaseOrder}/convert', [PurchaseOrderController::class, 'convertToPurchase']);
        Route::get('/purchase-orders/{purchaseOrder}/pdf', [PurchaseOrderController::class, 'generatePdf']);
        Route::get('/purchase-orders/{purchaseOrder}/stream', [PurchaseOrderController::class, 'streamPdf']);
    });

    // Sales
    Route::middleware('check.feature:sales')->group(function () {
        Route::apiResource('sales', SaleController::class);
        Route::post('/sales/{sale}/confirm', [SaleController::class, 'confirm']);
        Route::post('/sales/{sale}/return', [SaleController::class, 'createReturn']);
        Route::get('/sales/{sale}/facture/pdf', [SaleController::class, 'generateFacturePdf']);
        Route::get('/sales/{sale}/facture/stream', [SaleController::class, 'streamFacturePdf']);
        Route::get('/sales/{sale}/bon-livraison/pdf', [SaleController::class, 'generateBonLivraisonPdf']);
        Route::get('/sales/{sale}/bon-livraison/stream', [SaleController::class, 'streamBonLivraisonPdf']);
    });

    // Stock Movements & Returns
    Route::middleware('check.feature:stock_movements')->group(function () {
        Route::get('/stock-movements', [StockMovementController::class, 'index']);
        Route::get('/stock-movements/product/{productId}', [StockMovementController::class, 'getByProduct']);
        Route::get('/stock-movements/summary', [StockMovementController::class, 'getSummary']);
    });
    Route::middleware('check.feature:purchase_returns')->group(function () {
        Route::get('/purchase-returns', [StockMovementController::class, 'purchaseReturns']);
        Route::get('/purchase-returns/{id}', [StockMovementController::class, 'purchaseReturnShow']);
    });
    Route::middleware('check.feature:sale_returns')->group(function () {
        Route::get('/sale-returns', [StockMovementController::class, 'saleReturns']);
        Route::get('/sale-returns/{id}', [StockMovementController::class, 'saleReturnShow']);
    });

    // Payments
    Route::middleware('check.feature:payments')->group(function () {
        Route::get('/payments', [PaymentController::class, 'index']);
        Route::get('/payments/{payment}', [PaymentController::class, 'show']);
        Route::delete('/payments/{payment}', [PaymentController::class, 'destroy']);
        Route::post('/purchases/{purchase}/payments', [PaymentController::class, 'storePurchasePayment']);
        Route::post('/sales/{sale}/payments', [PaymentController::class, 'storeSalePayment']);
        Route::post('/clients/{client}/payments', [PaymentController::class, 'storeClientPayment']);
    });

    // Adjustments
    Route::middleware('check.feature:adjustments')->group(function () {
        Route::apiResource('adjustments', AdjustmentController::class)->except(['update']);
        Route::post('/adjustments/{adjustment}/approve', [AdjustmentController::class, 'approve']);
        Route::post('/adjustments/{adjustment}/reject', [AdjustmentController::class, 'reject']);
    });

    // Inventory Management
    Route::middleware('check.feature:inventory')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index']);
        Route::get('/inventory/warehouse/{warehouseId}', [InventoryController::class, 'getByWarehouse']);
        Route::post('/inventory/adjust', [InventoryController::class, 'adjust']);
        Route::post('/inventory/count', [InventoryController::class, 'count']);
        Route::post('/inventory/transfer', [InventoryController::class, 'transfer']);
        Route::get('/inventory/report', [InventoryController::class, 'report']);
    });

    // Trips
    Route::middleware('check.feature:trips')->group(function () {
        Route::apiResource('trips', TripController::class)->except(['update', 'destroy']);
        Route::post('/trips/{trip}/stores', [TripController::class, 'addStore']);
        Route::post('/trips/{trip}/stores/{store}/visit', [TripController::class, 'visitStore']);
        Route::post('/trips/{trip}/stores/{store}/skip', [TripController::class, 'skipStore']);
        Route::post('/trips/{trip}/complete', [TripController::class, 'complete']);
        Route::post('/trips/{trip}/cancel', [TripController::class, 'cancel']);
        Route::get('/my-active-trip', [TripController::class, 'getMyActiveTrip']);
        Route::get('/my-trips', [TripController::class, 'getMyTrips']);
    });

    // Orders
    Route::middleware('check.feature:orders')->group(function () {
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
    });

    // Deliveries
    Route::middleware('check.feature:deliveries')->group(function () {
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
    });

    // Van Sales
    Route::middleware('check.feature:van_sessions')->group(function () {
        Route::apiResource('van-sessions', VanSessionController::class)->except(['update']);
        Route::put('/van-sessions/{vanSession}', [VanSessionController::class, 'update']);
        Route::post('/van-sessions/{vanSession}/start', [VanSessionController::class, 'start']);
        Route::post('/van-sessions/{vanSession}/complete', [VanSessionController::class, 'complete']);
        Route::post('/van-sessions/{vanSession}/cancel', [VanSessionController::class, 'cancel']);
        Route::post('/van-sessions/{vanSession}/sales', [VanSessionController::class, 'createSale']);
        Route::get('/van-sessions/{vanSession}/sales', [VanSessionController::class, 'getSales']);
        Route::get('/van-sessions/{vanSession}/products', [VanSessionController::class, 'getAvailableProducts']);
        Route::get('/van-sessions/{vanSession}/stats', [VanSessionController::class, 'getStats']);
        Route::get('/my-active-van-session', [VanSessionController::class, 'getActiveSession']);
    });

    // Product Requests
    Route::middleware('check.feature:product_requests')->group(function () {
        Route::get('/product-requests', [ProductRequestController::class, 'index']);
        Route::get('/product-requests/pending-count', [ProductRequestController::class, 'pendingCount']);
        Route::get('/product-requests/my', [ProductRequestController::class, 'myRequests']);
        Route::post('/product-requests', [ProductRequestController::class, 'store']);
        Route::get('/product-requests/{productRequest}', [ProductRequestController::class, 'show']);
        Route::post('/product-requests/{productRequest}/approve', [ProductRequestController::class, 'approve']);
        Route::post('/product-requests/{productRequest}/reject', [ProductRequestController::class, 'reject']);
        Route::post('/product-requests/{productRequest}/fulfill', [ProductRequestController::class, 'fulfill']);
        Route::put('/product-requests/{productRequest}', [ProductRequestController::class, 'update']);
        Route::delete('/product-requests/{productRequest}', [ProductRequestController::class, 'destroy']);
    });

    // Stock Transfers
    Route::middleware('check.feature:stock_transfers')->group(function () {
        Route::apiResource('stock-transfers', StockTransferController::class);
        Route::post('/stock-transfers/{stockTransfer}/approve', [StockTransferController::class, 'approve']);
        Route::post('/stock-transfers/{stockTransfer}/collect', [StockTransferController::class, 'collect']);
        Route::get('/my-pending-transfers', [StockTransferController::class, 'myPendingTransfers']);
        Route::get('/my-stock', [StockTransferController::class, 'myStock']);
        Route::get('/my-sales', [StockTransferController::class, 'mySales']);
    });

    // Livreur Stock
    Route::middleware('check.feature:livreur_stock')->group(function () {
        Route::get('/livreur-stock', [DeliveryController::class, 'livreurStock']);
        Route::post('/livreur-stock/{userId}/return', [DeliveryController::class, 'returnStock']);
    });

    // Debtors
    Route::get('/debtors', [DeliveryController::class, 'getDebtors']);
    Route::get('/debtors/{clientId}', [DeliveryController::class, 'getClientDebt']);
    Route::get('/all-debtors', [DeliveryController::class, 'getAllDebtors']);
    Route::get('/all-debtors/{clientId}', [DeliveryController::class, 'getAllClientDebt']);

    // Sync (Mobile)
    Route::get('/sync/master-data', [SyncController::class, 'getMasterData']);
    Route::post('/sync/push', [SyncController::class, 'pushChanges']);
    Route::get('/sync/logs', [SyncController::class, 'getSyncLogs']);

    // Reports
    Route::middleware('check.feature:reports')->prefix('reports')->group(function () {
        Route::get('/sales/summary', [ReportController::class, 'salesSummary']);
        Route::get('/sales/by-product', [ReportController::class, 'salesByProduct']);
        Route::get('/sales/by-client', [ReportController::class, 'salesByClient']);
        Route::get('/sales/by-seller', [ReportController::class, 'salesBySeller']);
        Route::get('/delivery/summary', [ReportController::class, 'deliverySummary']);
        Route::get('/delivery/by-livreur', [ReportController::class, 'deliveryByLivreur']);
        Route::get('/delivery/details', [ReportController::class, 'deliveryDetails']);
        Route::get('/stock/summary', [ReportController::class, 'stockSummary']);
        Route::get('/stock/movements', [ReportController::class, 'stockMovements']);
        Route::get('/stock/low-stock', [ReportController::class, 'lowStockAlert']);
        Route::get('/financial/summary', [ReportController::class, 'financialSummary']);
        Route::get('/financial/client-balances', [ReportController::class, 'clientBalances']);
        Route::get('/financial/collections', [ReportController::class, 'collectionsReport']);
        Route::get('/debt/summary', [ReportController::class, 'debtSummary']);
        Route::get('/debt/details', [ReportController::class, 'debtDetails']);
        Route::get('/debt/aging', [ReportController::class, 'debtAging']);
        Route::get('/debt/client/{clientId}', [ReportController::class, 'debtByClient']);
    });

    // Location Tracking / Driver Map
    Route::middleware('check.feature:drivers_map')->group(function () {
        Route::post('/location/update', [LocationController::class, 'updateLocation']);
        Route::get('/location/drivers', [LocationController::class, 'getAllDrivers']);
        Route::get('/location/drivers/active', [LocationController::class, 'getActiveDrivers']);
    });

    // Settings
    Route::middleware('check.feature:settings')->group(function () {
        Route::get('/settings', [SettingController::class, 'index']);
        Route::get('/settings/group/{group}', [SettingController::class, 'getByGroup']);
        Route::put('/settings', [SettingController::class, 'update']);
        Route::post('/settings/logo', [SettingController::class, 'uploadLogo']);
        Route::delete('/settings/logo', [SettingController::class, 'deleteLogo']);
        Route::get('/settings/company-info', [SettingController::class, 'getCompanyInfo']);
        Route::get('/settings/has-password', [SettingController::class, 'hasPassword']);
        Route::post('/settings/verify-password', [SettingController::class, 'verifyPassword']);
        Route::post('/settings/set-password', [SettingController::class, 'setPassword']);
        Route::post('/settings/remove-password', [SettingController::class, 'removePassword']);
        Route::post('/backup/create', [BackupController::class, 'create']);
        Route::post('/backup/restore', [BackupController::class, 'restore']);
        Route::post('/backup/info', [BackupController::class, 'info']);
        Route::get('/backup/export-sql', [BackupController::class, 'exportSql']);
    });

    // Employees
    Route::middleware('check.feature:employees')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::get('/employees/active', [EmployeeController::class, 'getActive']);
        Route::get('/employees/{employee}', [EmployeeController::class, 'show']);
        Route::put('/employees/{employee}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{employee}', [EmployeeController::class, 'destroy']);
        Route::post('/employees/{employee}/toggle-active', [EmployeeController::class, 'toggleActive']);
    });

    // Caisses (Cash Registers)
    Route::middleware('check.feature:caisses')->group(function () {
        Route::get('/caisses/my', [CaisseController::class, 'myCaisse']);
        Route::get('/caisses/summary', [CaisseController::class, 'summary']);
        Route::get('/caisses/summary-period', [CaisseController::class, 'periodSummary']);
        Route::post('/caisses/transfer', [CaisseController::class, 'transfer']);
        Route::get('/caisses/{id}/transactions', [CaisseController::class, 'transactions']);
        Route::post('/caisses/{id}/settle', [CaisseController::class, 'settle']);
        Route::post('/caisses/{id}/adjust', [CaisseController::class, 'adjust']);
        Route::get('/caisses', [CaisseController::class, 'index']);
        Route::post('/caisses', [CaisseController::class, 'store']);
        Route::put('/caisses/{id}', [CaisseController::class, 'update']);
        Route::get('/caisses/{id}', [CaisseController::class, 'show']);
    });

    // Dispenses (Expenses)
    Route::middleware('check.feature:dispenses')->group(function () {
        Route::get('/dispenses', [DispenseController::class, 'index']);
        Route::post('/dispenses', [DispenseController::class, 'store']);
        Route::get('/dispenses/categories', [DispenseController::class, 'getCategories']);
        Route::get('/dispenses/summary', [DispenseController::class, 'summary']);
        Route::get('/dispenses/{dispense}', [DispenseController::class, 'show']);
        Route::put('/dispenses/{dispense}', [DispenseController::class, 'update']);
        Route::delete('/dispenses/{dispense}', [DispenseController::class, 'destroy']);
    });

    }); // End verified.email middleware group
});
