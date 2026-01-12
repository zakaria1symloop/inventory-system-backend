<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Stock;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        // Eager load stock for specific warehouse if provided
        if ($warehouseId) {
            $query = Product::with(['category', 'brand', 'unitBuy', 'unitSale',
                'stock' => function ($q) use ($warehouseId) {
                    $q->where('warehouse_id', $warehouseId);
                }
            ]);
        } else {
            $query = Product::with(['category', 'brand', 'unitBuy', 'unitSale', 'stock']);
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('barcode', 'like', "%{$request->search}%");
            });
        }

        if ($request->category_id) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->brand_id) {
            $query->where('brand_id', $request->brand_id);
        }

        if ($request->active_only) {
            $query->where('is_active', true);
        }

        if ($request->low_stock) {
            $query->lowStock();
        }

        $products = $query->latest()->paginate($request->per_page ?? 15);

        // If warehouse_id is specified, calculate available stock for each product
        if ($warehouseId) {
            $products->getCollection()->transform(function ($product) use ($warehouseId) {
                $product->available_stock = Stock::getAvailableStock($product->id, $warehouseId);
                return $product;
            });
        }

        return response()->json($products);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_buy_id' => 'nullable|exists:units,id',
            'unit_sale_id' => 'nullable|exists:units,id',
            'barcode' => 'nullable|unique:products',
            'cost_price' => 'required|numeric|min:0',
            'retail_price' => 'required|numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'min_selling_price' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'tax_type' => 'in:exclusive,inclusive',
            'discount_type' => 'in:percent,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'stock_alert' => 'nullable|integer|min:0',
            'opening_stock' => 'nullable|numeric|min:0',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'image' => 'nullable|image|max:2048',
        ]);

        $data = $request->except(['image', 'warehouse_id', 'opening_stock']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product = Product::create($data);

        if ($request->opening_stock && $request->warehouse_id) {
            Stock::create([
                'product_id' => $product->id,
                'warehouse_id' => $request->warehouse_id,
                'quantity' => $request->opening_stock,
            ]);
        }

        return response()->json($product->load(['category', 'brand', 'unitBuy', 'unitSale']), 201);
    }

    public function show(Product $product)
    {
        return response()->json($product->load(['category', 'brand', 'unitBuy', 'unitSale', 'stock.warehouse']));
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'string|max:255',
            'category_id' => 'nullable|exists:categories,id',
            'brand_id' => 'nullable|exists:brands,id',
            'unit_buy_id' => 'nullable|exists:units,id',
            'unit_sale_id' => 'nullable|exists:units,id',
            'barcode' => 'nullable|unique:products,barcode,' . $product->id,
            'cost_price' => 'numeric|min:0',
            'retail_price' => 'numeric|min:0',
            'wholesale_price' => 'nullable|numeric|min:0',
            'min_selling_price' => 'nullable|numeric|min:0',
            'tax_percent' => 'nullable|numeric|min:0|max:100',
            'tax_type' => 'in:exclusive,inclusive',
            'discount_type' => 'in:percent,fixed',
            'discount_value' => 'nullable|numeric|min:0',
            'stock_alert' => 'nullable|integer|min:0',
            'image' => 'nullable|image|max:2048',
        ]);

        $data = $request->except(['image']);

        if ($request->hasFile('image')) {
            $data['image'] = $request->file('image')->store('products', 'public');
        }

        $product->update($data);

        return response()->json($product->load(['category', 'brand', 'unitBuy', 'unitSale']));
    }

    public function destroy(Product $product)
    {
        // Check if product has stock in any warehouse
        $totalStock = $product->stock()->sum('quantity');
        if ($totalStock > 0) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. يوجد كمية في المخزون (' . $totalStock . '). قم بإفراغ المخزون أولاً'
            ], 400);
        }

        // Check if product is used in any purchase items
        if ($product->purchaseItems()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. تم استخدامه في فواتير شراء'
            ], 400);
        }

        // Check if product is used in any sale items
        if ($product->saleItems()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. تم استخدامه في فواتير بيع'
            ], 400);
        }

        // Check if product is used in any order items
        if ($product->orderItems()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف المنتج. تم استخدامه في طلبات'
            ], 400);
        }

        $product->delete();

        return response()->json(['message' => 'تم حذف المنتج بنجاح']);
    }

    public function generateBarcode()
    {
        return response()->json(['barcode' => Product::generateBarcode()]);
    }

    public function findByBarcode(Request $request)
    {
        $request->validate(['barcode' => 'required']);

        $product = Product::where('barcode', $request->barcode)->first();

        if (!$product) {
            return response()->json(['message' => 'المنتج غير موجود'], 404);
        }

        return response()->json($product->load(['category', 'brand', 'unitBuy', 'unitSale', 'stock']));
    }

    public function getStock(Product $product, Request $request)
    {
        $warehouseId = $request->warehouse_id;

        if ($warehouseId) {
            $stock = $product->getStockInWarehouse($warehouseId);
        } else {
            $stock = $product->getTotalStock();
        }

        return response()->json(['quantity' => $stock]);
    }

    /**
     * Get available stock for a product (current stock minus reserved quantities)
     */
    public function getAvailableStock(Product $product, Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
        ]);

        $availableQty = Stock::getAvailableStock($product->id, $request->warehouse_id);

        return response()->json([
            'quantity' => $availableQty,
            'product_id' => $product->id,
            'warehouse_id' => $request->warehouse_id,
        ]);
    }

    /**
     * Get available stock for multiple products in a warehouse
     */
    public function getAvailableStockBulk(Request $request)
    {
        $request->validate([
            'warehouse_id' => 'required|exists:warehouses,id',
            'product_ids' => 'nullable|array',
            'product_ids.*' => 'exists:products,id',
        ]);

        $warehouseId = $request->warehouse_id;
        $productIds = $request->product_ids;

        $query = Product::with(['stock' => function ($q) use ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }]);

        if ($productIds) {
            $query->whereIn('id', $productIds);
        }

        $products = $query->get();

        $result = $products->map(function ($product) use ($warehouseId) {
            $currentStock = $product->stock->first()?->quantity ?? 0;
            $availableStock = Stock::getAvailableStock($product->id, $warehouseId);

            return [
                'product_id' => $product->id,
                'product_name' => $product->name,
                'current_stock' => (float) $currentStock,
                'available_stock' => $availableStock,
                'reserved' => (float) $currentStock - $availableStock,
            ];
        });

        return response()->json($result);
    }
}
