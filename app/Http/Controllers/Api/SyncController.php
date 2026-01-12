<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Client;
use App\Models\Product;
use App\Models\SyncLog;
use App\Models\Unit;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SyncController extends Controller
{
    public function getMasterData(Request $request)
    {
        $lastSync = $request->last_sync ? \Carbon\Carbon::parse($request->last_sync) : null;

        $data = [
            'categories' => $this->getUpdatedRecords(Category::class, $lastSync),
            'brands' => $this->getUpdatedRecords(Brand::class, $lastSync),
            'units' => $this->getUpdatedRecords(Unit::class, $lastSync),
            'products' => $this->getUpdatedRecords(Product::class, $lastSync, ['category', 'brand', 'unitBuy', 'unitSale']),
            'clients' => $this->getUpdatedRecords(Client::class, $lastSync),
            'warehouses' => $this->getUpdatedRecords(Warehouse::class, $lastSync),
            'sync_time' => now()->toISOString(),
        ];

        return response()->json($data);
    }

    private function getUpdatedRecords($model, $lastSync, $relations = [])
    {
        $query = $model::query();

        if ($relations) {
            $query->with($relations);
        }

        if ($lastSync) {
            $query->where('updated_at', '>', $lastSync);
        }

        return $query->get();
    }

    public function pushChanges(Request $request)
    {
        $request->validate([
            'device_id' => 'required|string',
            'changes' => 'required|array',
            'changes.*.entity_type' => 'required|string',
            'changes.*.entity_id' => 'nullable|integer',
            'changes.*.action' => 'required|in:create,update,delete',
            'changes.*.data' => 'required|array',
            'changes.*.local_id' => 'nullable|string',
        ]);

        $results = [];

        DB::beginTransaction();

        try {
            foreach ($request->changes as $change) {
                $result = $this->processChange($change, $request->device_id);
                $results[] = $result;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'results' => $results,
                'sync_time' => now()->toISOString(),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 500);
        }
    }

    private function processChange($change, $deviceId)
    {
        $entityType = $change['entity_type'];
        $action = $change['action'];
        $data = $change['data'];
        $localId = $change['local_id'] ?? null;

        $modelClass = $this->getModelClass($entityType);
        if (!$modelClass) {
            return [
                'local_id' => $localId,
                'success' => false,
                'message' => 'نوع الكيان غير معروف',
            ];
        }

        $result = [
            'local_id' => $localId,
            'entity_type' => $entityType,
            'action' => $action,
        ];

        try {
            switch ($action) {
                case 'create':
                    $entity = $modelClass::create($data);
                    $result['server_id'] = $entity->id;
                    $result['success'] = true;
                    break;

                case 'update':
                    $entity = $modelClass::find($change['entity_id']);
                    if ($entity) {
                        $entity->update($data);
                        $result['server_id'] = $entity->id;
                        $result['success'] = true;
                    } else {
                        $result['success'] = false;
                        $result['message'] = 'الكيان غير موجود';
                    }
                    break;

                case 'delete':
                    $entity = $modelClass::find($change['entity_id']);
                    if ($entity) {
                        $entity->delete();
                        $result['success'] = true;
                    } else {
                        $result['success'] = false;
                        $result['message'] = 'الكيان غير موجود';
                    }
                    break;
            }

            SyncLog::create([
                'user_id' => auth()->id(),
                'device_id' => $deviceId,
                'entity_type' => $entityType,
                'entity_id' => $result['server_id'] ?? $change['entity_id'],
                'action' => $action,
                'data' => $data,
                'synced_at' => now(),
                'status' => $result['success'] ? 'synced' : 'failed',
            ]);
        } catch (\Exception $e) {
            $result['success'] = false;
            $result['message'] = $e->getMessage();
        }

        return $result;
    }

    private function getModelClass($entityType)
    {
        $models = [
            'orders' => \App\Models\Order::class,
            'order_items' => \App\Models\OrderItem::class,
            'trips' => \App\Models\Trip::class,
            'trip_stores' => \App\Models\TripStore::class,
            'delivery_orders' => \App\Models\DeliveryOrder::class,
            'delivery_returns' => \App\Models\DeliveryReturn::class,
            'clients' => \App\Models\Client::class,
        ];

        return $models[$entityType] ?? null;
    }

    public function getSyncLogs(Request $request)
    {
        $logs = SyncLog::where('user_id', auth()->id())
            ->when($request->device_id, fn($q) => $q->where('device_id', $request->device_id))
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->per_page ?? 50);

        return response()->json($logs);
    }
}
