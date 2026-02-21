<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientCategory;
use App\Models\Product;
use App\Models\ProductCategoryPrice;
use Illuminate\Http\Request;

class ClientCategoryController extends Controller
{
    public function index()
    {
        $categories = ClientCategory::withCount('clients')->latest()->get();

        return response()->json($categories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ]);

        // If setting as default, clear other defaults
        if ($request->is_default) {
            ClientCategory::where('is_default', true)->update(['is_default' => false]);
        }

        $category = ClientCategory::create($request->only(['name', 'description', 'is_default']));

        // If this is the new default, update all products' retail_price
        if ($category->is_default) {
            $this->updateProductRetailPrices($category->id);
        }

        return response()->json($category->loadCount('clients'), 201);
    }

    public function show(ClientCategory $clientCategory)
    {
        return response()->json($clientCategory->loadCount('clients'));
    }

    public function update(Request $request, ClientCategory $clientCategory)
    {
        $request->validate([
            'name' => 'string|max:255',
            'description' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ]);

        // If setting as default, clear other defaults
        if ($request->is_default && !$clientCategory->is_default) {
            ClientCategory::where('is_default', true)->update(['is_default' => false]);
        }

        $clientCategory->update($request->only(['name', 'description', 'is_default']));

        // If this is now the default, update all products' retail_price
        if ($clientCategory->is_default) {
            $this->updateProductRetailPrices($clientCategory->id);
        }

        return response()->json($clientCategory->loadCount('clients'));
    }

    public function destroy(ClientCategory $clientCategory)
    {
        // Block deleting the retail price (default) category
        if ($clientCategory->is_default) {
            return response()->json([
                'message' => 'لا يمكن حذف فئة سعر البيع. قم بتعيين فئة أخرى كسعر بيع أولاً'
            ], 400);
        }

        if ($clientCategory->clients()->exists()) {
            return response()->json([
                'message' => 'لا يمكن حذف الفئة. يوجد عملاء مرتبطون بها'
            ], 400);
        }

        $clientCategory->delete();

        return response()->json(['message' => 'تم حذف الفئة بنجاح']);
    }

    /**
     * Update all products' retail_price from the given default category's prices
     */
    private function updateProductRetailPrices(int $categoryId): void
    {
        $categoryPrices = ProductCategoryPrice::where('client_category_id', $categoryId)->get();

        foreach ($categoryPrices as $cp) {
            Product::where('id', $cp->product_id)->update(['retail_price' => $cp->price]);
        }
    }
}
