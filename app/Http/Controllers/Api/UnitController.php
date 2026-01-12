<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $query = Unit::with('baseUnit', 'subUnits');

        if ($request->active_only) {
            $query->active();
        }

        if ($request->base_only) {
            $query->base();
        }

        if ($request->search) {
            $query->where('name', 'like', "%{$request->search}%")
                  ->orWhere('short_name', 'like', "%{$request->search}%");
        }

        $units = $query->latest()->get();

        return response()->json($units);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'short_name' => 'required|string|max:50',
            'base_unit_id' => 'nullable|exists:units,id',
            'operator' => 'nullable|in:*,/',
            'operation_value' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $unit = Unit::create($request->all());

        return response()->json($unit->load(['baseUnit', 'subUnits']), 201);
    }

    public function show(Unit $unit)
    {
        return response()->json($unit->load(['baseUnit', 'subUnits']));
    }

    public function update(Request $request, Unit $unit)
    {
        $request->validate([
            'name' => 'string|max:255',
            'short_name' => 'string|max:50',
            'base_unit_id' => 'nullable|exists:units,id',
            'operator' => 'nullable|in:*,/',
            'operation_value' => 'nullable|numeric|min:0',
            'is_active' => 'boolean',
        ]);

        $unit->update($request->all());

        return response()->json($unit->load(['baseUnit', 'subUnits']));
    }

    public function destroy(Unit $unit)
    {
        $unit->delete();

        return response()->json(['message' => 'تم حذف الوحدة بنجاح']);
    }

    public function convert(Request $request)
    {
        $request->validate([
            'from_unit_id' => 'required|exists:units,id',
            'to_unit_id' => 'required|exists:units,id',
            'quantity' => 'required|numeric',
        ]);

        $fromUnit = Unit::find($request->from_unit_id);
        $toUnit = Unit::find($request->to_unit_id);

        $baseQuantity = $fromUnit->convertToBase($request->quantity);
        $convertedQuantity = $toUnit->convertFromBase($baseQuantity);

        return response()->json([
            'from_quantity' => $request->quantity,
            'from_unit' => $fromUnit->short_name,
            'to_quantity' => $convertedQuantity,
            'to_unit' => $toUnit->short_name,
        ]);
    }
}
