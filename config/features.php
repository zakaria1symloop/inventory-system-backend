<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Available Modules
    |--------------------------------------------------------------------------
    | All feature keys with Arabic names and groups.
    */

    'modules' => [
        // Inventory
        'products'        => ['name' => 'المنتجات', 'group' => 'المخزون'],
        'categories'      => ['name' => 'الأصناف', 'group' => 'المخزون'],
        'brands'          => ['name' => 'العلامات التجارية', 'group' => 'المخزون'],
        'units'           => ['name' => 'الوحدات', 'group' => 'المخزون'],
        'warehouses'      => ['name' => 'المستودعات', 'group' => 'المخزون'],
        'stock_movements' => ['name' => 'حركات المخزون', 'group' => 'المخزون'],
        'adjustments'     => ['name' => 'التعديلات', 'group' => 'المخزون'],
        'inventory'       => ['name' => 'إدارة المخزون', 'group' => 'المخزون'],

        // Purchases
        'purchases'        => ['name' => 'فواتير الشراء', 'group' => 'المشتريات'],
        'purchase_orders'  => ['name' => 'بونات الطلب', 'group' => 'المشتريات'],
        'suppliers'        => ['name' => 'الموردين', 'group' => 'المشتريات'],
        'purchase_returns' => ['name' => 'مرتجعات الشراء', 'group' => 'المشتريات'],

        // Sales
        'sales'             => ['name' => 'فواتير البيع', 'group' => 'المبيعات'],
        'clients'           => ['name' => 'العملاء', 'group' => 'المبيعات'],
        'client_categories' => ['name' => 'فئات العملاء', 'group' => 'المبيعات'],
        'sale_returns'      => ['name' => 'مرتجعات المبيعات', 'group' => 'المبيعات'],

        // Orders & Delivery
        'orders'           => ['name' => 'الطلبات', 'group' => 'الطلبات والتوصيل'],
        'trips'            => ['name' => 'الجولات', 'group' => 'الطلبات والتوصيل'],
        'deliveries'       => ['name' => 'التوصيل', 'group' => 'الطلبات والتوصيل'],
        'stock_transfers'  => ['name' => 'تحويلات المخزون', 'group' => 'الطلبات والتوصيل'],
        'product_requests' => ['name' => 'طلبات المنتجات', 'group' => 'الطلبات والتوصيل'],
        'livreur_stock'    => ['name' => 'مخزون السائقين', 'group' => 'الطلبات والتوصيل'],
        'drivers_map'      => ['name' => 'خريطة السائقين', 'group' => 'الطلبات والتوصيل'],
        'vehicles'         => ['name' => 'المركبات', 'group' => 'الطلبات والتوصيل'],

        // Van Sales
        'van_sessions' => ['name' => 'جلسات الكاش فان', 'group' => 'الكاش فان'],
        'van_sales'    => ['name' => 'مبيعات الكاش فان', 'group' => 'الكاش فان'],

        // Finance
        'payments'  => ['name' => 'المدفوعات', 'group' => 'المالية'],
        'caisses'   => ['name' => 'الصناديق', 'group' => 'المالية'],
        'dispenses' => ['name' => 'المصروفات', 'group' => 'المالية'],
        'reports'   => ['name' => 'التقارير', 'group' => 'المالية'],

        // Admin
        'users'     => ['name' => 'المستخدمين', 'group' => 'الإدارة'],
        'employees' => ['name' => 'الموظفين', 'group' => 'الإدارة'],
        'settings'  => ['name' => 'الإعدادات', 'group' => 'الإدارة'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plan Defaults
    |--------------------------------------------------------------------------
    | Default features for each plan. When a tenant's `features` is null,
    | they get these defaults based on their plan.
    */

    'plan_defaults' => [
        'free' => [
            'products', 'categories', 'brands', 'units', 'warehouses',
            'stock_movements', 'adjustments', 'inventory',
            'purchases', 'purchase_orders', 'suppliers', 'purchase_returns',
            'sales', 'clients', 'client_categories', 'sale_returns',
            'orders', 'trips', 'deliveries', 'stock_transfers',
            'product_requests', 'livreur_stock', 'drivers_map', 'vehicles',
            'van_sessions', 'van_sales',
            'payments', 'caisses', 'dispenses', 'reports',
            'users', 'employees', 'settings',
        ],
        'starter' => [  // legacy
            'products', 'categories', 'brands', 'units', 'warehouses',
            'stock_movements', 'adjustments', 'inventory',
            'purchases', 'purchase_orders', 'suppliers', 'purchase_returns',
            'sales', 'clients', 'client_categories', 'sale_returns',
            'orders', 'trips', 'deliveries', 'stock_transfers',
            'product_requests', 'livreur_stock', 'drivers_map', 'vehicles',
            'van_sessions', 'van_sales',
            'payments', 'caisses', 'dispenses', 'reports',
            'users', 'employees', 'settings',
        ],
        'pro' => [
            'products', 'categories', 'brands', 'units', 'warehouses',
            'stock_movements', 'adjustments', 'inventory',
            'purchases', 'purchase_orders', 'suppliers', 'purchase_returns',
            'sales', 'clients', 'client_categories', 'sale_returns',
            'orders', 'trips', 'deliveries', 'stock_transfers',
            'product_requests', 'livreur_stock', 'drivers_map', 'vehicles',
            'van_sessions', 'van_sales',
            'payments', 'caisses', 'dispenses', 'reports',
            'users', 'employees', 'settings',
        ],
        'pro_ai' => [
            'products', 'categories', 'brands', 'units', 'warehouses',
            'stock_movements', 'adjustments', 'inventory',
            'purchases', 'purchase_orders', 'suppliers', 'purchase_returns',
            'sales', 'clients', 'client_categories', 'sale_returns',
            'orders', 'trips', 'deliveries', 'stock_transfers',
            'product_requests', 'livreur_stock', 'drivers_map', 'vehicles',
            'van_sessions', 'van_sales',
            'payments', 'caisses', 'dispenses', 'reports',
            'users', 'employees', 'settings',
            'ai_analytics', 'ai_forecasting', 'ai_recommendations',
        ],
        'business' => [  // legacy
            'products', 'categories', 'brands', 'units', 'warehouses',
            'stock_movements', 'adjustments', 'inventory',
            'purchases', 'purchase_orders', 'suppliers', 'purchase_returns',
            'sales', 'clients', 'client_categories', 'sale_returns',
            'orders', 'trips', 'deliveries', 'stock_transfers',
            'product_requests', 'livreur_stock', 'drivers_map', 'vehicles',
            'van_sessions', 'van_sales',
            'payments', 'caisses', 'dispenses', 'reports',
            'users', 'employees', 'settings',
        ],
        'enterprise' => [
            'products', 'categories', 'brands', 'units', 'warehouses',
            'stock_movements', 'adjustments', 'inventory',
            'purchases', 'purchase_orders', 'suppliers', 'purchase_returns',
            'sales', 'clients', 'client_categories', 'sale_returns',
            'orders', 'trips', 'deliveries', 'stock_transfers',
            'product_requests', 'livreur_stock', 'drivers_map', 'vehicles',
            'van_sessions', 'van_sales',
            'payments', 'caisses', 'dispenses', 'reports',
            'users', 'employees', 'settings',
            'ai_analytics', 'ai_forecasting', 'ai_recommendations',
            'dedicated_servers', 'custom_api', 'sla',
        ],
    ],

];
