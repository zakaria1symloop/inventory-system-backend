<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\Request;

class VehicleController extends Controller
{
    public function index(Request $request)
    {
        $query = Vehicle::query();

        if ($request->active_only) {
            $query->active();
        }

        if ($request->search) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('plate_number', 'like', "%{$request->search}%");
            });
        }

        $vehicles = $query->latest()->get();

        return response()->json($vehicles);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'plate_number' => 'nullable|string',
            'model' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $vehicle = Vehicle::create($request->all());

        return response()->json($vehicle, 201);
    }

    public function show(Vehicle $vehicle)
    {
        return response()->json($vehicle->load(['trips', 'deliveries']));
    }

    public function update(Request $request, Vehicle $vehicle)
    {
        $request->validate([
            'name' => 'string|max:255',
            'plate_number' => 'nullable|string',
            'model' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $vehicle->update($request->all());

        return response()->json($vehicle);
    }

    public function destroy(Vehicle $vehicle)
    {
        $vehicle->delete();

        return response()->json(['message' => 'تم حذف المركبة بنجاح']);
    }
}
