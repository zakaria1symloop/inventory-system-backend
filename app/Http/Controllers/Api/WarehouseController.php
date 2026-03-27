<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseController extends Controller
{
    public function index(Request $request)
    {
        $query = Warehouse::withCount('stock')->with('assignedUser:id,name,role,warehouse_id');

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

    public function assignUser(Request $request, Warehouse $warehouse)
    {
        $request->validate([
            'user_id' => 'nullable|exists:users,id',
        ]);

        $userId = $request->user_id;

        if ($userId) {
            // Check if this user already has a different warehouse
            $existingUser = User::find($userId);
            if ($existingUser->warehouse_id && $existingUser->warehouse_id !== $warehouse->id) {
                return response()->json([
                    'message' => 'هذا المستخدم لديه مستودع آخر بالفعل. يجب إزالته من المستودع الحالي أولاً',
                ], 422);
            }

            // Remove any other user currently assigned to this warehouse
            User::where('warehouse_id', $warehouse->id)->update(['warehouse_id' => null]);

            // Assign the new user
            $existingUser->update(['warehouse_id' => $warehouse->id]);
        } else {
            // Unassign: remove any user from this warehouse
            User::where('warehouse_id', $warehouse->id)->update(['warehouse_id' => null]);
        }

        return response()->json($warehouse->load('assignedUser:id,name,email,role,warehouse_id'));
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
