<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Carbon\Carbon;

class LocationController extends Controller
{
    /**
     * Update the current user's location
     */
    public function updateLocation(Request $request)
    {
        $request->validate([
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
        ]);

        $user = $request->user();

        $user->update([
            'latitude' => $request->latitude,
            'longitude' => $request->longitude,
            'last_location_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Location updated successfully',
        ]);
    }

    /**
     * Get all active drivers with their locations
     * Active = has updated location in the last 5 minutes
     */
    public function getActiveDrivers(Request $request)
    {
        $minutesAgo = $request->get('minutes', 5);
        $cutoffTime = Carbon::now()->subMinutes($minutesAgo);

        $drivers = User::where('role', 'livreur')
            ->where('is_active', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->where('last_location_at', '>=', $cutoffTime)
            ->select([
                'id',
                'name',
                'phone',
                'latitude',
                'longitude',
                'last_location_at',
            ])
            ->get()
            ->map(function ($driver) {
                // Check if driver has an active delivery
                $activeDelivery = $driver->deliveries()
                    ->whereIn('status', ['preparing', 'in_progress'])
                    ->with(['vehicle'])
                    ->first();

                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'phone' => $driver->phone,
                    'latitude' => (float) $driver->latitude,
                    'longitude' => (float) $driver->longitude,
                    'last_location_at' => $driver->last_location_at,
                    'has_active_delivery' => $activeDelivery !== null,
                    'delivery_reference' => $activeDelivery?->reference,
                    'vehicle_name' => $activeDelivery?->vehicle?->name,
                ];
            });

        return response()->json([
            'drivers' => $drivers,
            'count' => $drivers->count(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Get all drivers (both active and inactive) with their last known location
     */
    public function getAllDrivers(Request $request)
    {
        $drivers = User::where('role', 'livreur')
            ->where('is_active', true)
            ->select([
                'id',
                'name',
                'phone',
                'latitude',
                'longitude',
                'last_location_at',
            ])
            ->get()
            ->map(function ($driver) {
                $isOnline = $driver->last_location_at &&
                    Carbon::parse($driver->last_location_at)->isAfter(Carbon::now()->subMinutes(5));

                // Check if driver has an active delivery
                $activeDelivery = $driver->deliveries()
                    ->whereIn('status', ['preparing', 'in_progress'])
                    ->with(['vehicle'])
                    ->first();

                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'phone' => $driver->phone,
                    'latitude' => $driver->latitude ? (float) $driver->latitude : null,
                    'longitude' => $driver->longitude ? (float) $driver->longitude : null,
                    'last_location_at' => $driver->last_location_at,
                    'is_online' => $isOnline,
                    'has_active_delivery' => $activeDelivery !== null,
                    'delivery_reference' => $activeDelivery?->reference,
                    'vehicle_name' => $activeDelivery?->vehicle?->name,
                ];
            });

        return response()->json([
            'drivers' => $drivers,
            'online_count' => $drivers->where('is_online', true)->count(),
            'total_count' => $drivers->count(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
