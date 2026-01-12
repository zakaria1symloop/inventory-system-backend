<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = Warehouse::withCount('stock');

        if ($request->active_only) {
            $query->active();
        }

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%");
        }

        $warehouses = $query->latest()->get();

        return response()->json($warehouses);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($request->is_main) {
            Warehouse::where('is_main', true)->update(['is_main' => false]);
        }

        $warehouse = Warehouse::create($request->all());

        return response()->json($warehouse, 201);
    }

    public function show(Warehouse $warehouse)
    {
        return response()->json($warehouse->load('stock.product'));
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $request->validate([
            'name' => 'string|max:255',
            'address' => 'nullable|string',
            'phone' => 'nullable|string',
            'is_main' => 'boolean',
            'is_active' => 'boolean',
        ]);

        if ($request->is_main) {
            Warehouse::where('is_main', true)->where('id', '!=', $warehouse->id)->update(['is_main' => false]);
        }

        $warehouse->update($request->all());

        return response()->json($warehouse);
    }

    public function destroy(Warehouse $warehouse)
    {
        $warehouse->delete();

        return response()->json(['message' => 'تم حذف المستودع بنجاح']);
    }

    public function getStock(Warehouse $warehouse, Request $request)
    {
        $query = $warehouse->stock()->with('product');

        if ($request->low_stock) {
            $query->lowStock();
        }

        $stock = $query->get();

        return response()->json($stock);
    }
}
