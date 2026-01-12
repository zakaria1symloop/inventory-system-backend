<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Trip;
use App\Models\TripStore;
use Illuminate\Http\Request;

class TripController extends Controller
{
    public function index(Request $request)
    {
        $query = Trip::with(['seller', 'vehicle', 'stores.client']);

        if ($request->seller_id) {
            $query->where('seller_id', $request->seller_id);
        }

        if ($request->status) {
            $query->where('status', $request->status);
        }

        if ($request->from_date) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }

        if ($request->to_date) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }

        $trips = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($trips);
    }

    public function store(Request $request)
    {
        $request->validate([
            'vehicle_id' => 'nullable|exists:vehicles,id',
            'notes' => 'nullable|string',
            'stores' => 'nullable|array',
            'stores.*.client_id' => 'exists:clients,id',
            'stores.*.visit_order' => 'nullable|integer',
        ]);

        $trip = Trip::create([
            'seller_id' => auth()->id(),
            'vehicle_id' => $request->vehicle_id,
            'notes' => $request->notes,
            'status' => 'active',
            'start_time' => now(),
        ]);

        if ($request->stores) {
            foreach ($request->stores as $index => $store) {
                TripStore::create([
                    'trip_id' => $trip->id,
                    'client_id' => $store['client_id'],
                    'visit_order' => $store['visit_order'] ?? $index + 1,
                    'status' => 'pending',
                ]);
            }
        }

        return response()->json($trip->load(['seller', 'vehicle', 'stores.client']), 201);
    }

    public function show(Trip $trip)
    {
        return response()->json($trip->load(['seller', 'vehicle', 'stores.client', 'orders.items.product']));
    }

    public function addStore(Request $request, Trip $trip)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'visit_order' => 'nullable|integer',
        ]);

        $maxOrder = $trip->stores()->max('visit_order') ?? 0;

        $tripStore = TripStore::create([
            'trip_id' => $trip->id,
            'client_id' => $request->client_id,
            'visit_order' => $request->visit_order ?? $maxOrder + 1,
            'status' => 'pending',
        ]);

        return response()->json($tripStore->load('client'), 201);
    }

    public function visitStore(Request $request, Trip $trip, TripStore $store)
    {
        $store->markVisited();

        return response()->json($store->load('client'));
    }

    public function skipStore(Request $request, Trip $trip, TripStore $store)
    {
        $request->validate(['notes' => 'nullable|string']);

        $store->skip($request->notes);

        return response()->json($store->load('client'));
    }

    public function complete(Trip $trip)
    {
        $trip->complete();

        return response()->json($trip->load(['seller', 'vehicle', 'stores.client', 'orders']));
    }

    public function cancel(Trip $trip)
    {
        $trip->cancel();

        return response()->json($trip);
    }

    public function getMyActiveTrip(Request $request)
    {
        $trip = Trip::where('seller_id', auth()->id())
            ->where('status', 'active')
            ->with(['stores.client', 'orders.items.product'])
            ->first();

        return response()->json($trip);
    }

    public function getMyTrips(Request $request)
    {
        $trips = Trip::where('seller_id', auth()->id())
            ->with(['stores.client', 'orders'])
            ->latest()
            ->paginate($request->per_page ?? 15);

        return response()->json($trips);
    }
}
