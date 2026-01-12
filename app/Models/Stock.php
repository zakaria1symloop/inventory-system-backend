<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $table = 'stock';

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public static function updateStock($productId, $warehouseId, $quantity, $operation = 'add')
    {
        $stock = self::firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['quantity' => 0]
        );

        if ($operation === 'add') {
            $stock->quantity += $quantity;
        } else {
            $stock->quantity -= $quantity;
        }

        $stock->save();

        return $stock;
    }

    /**
     * Get available stock (current stock minus reserved quantities)
     * Reserved = pending/confirmed/assigned orders + deliveries in preparing status
     */
    public static function getAvailableStock($productId, $warehouseId, $excludeOrderId = null)
    {
        $stock = self::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        $currentQty = $stock ? (float) $stock->quantity : 0;

        // Get reserved quantity from pending, confirmed, and assigned orders
        $reservedFromOrders = \App\Models\OrderItem::whereHas('order', function ($q) use ($warehouseId, $excludeOrderId) {
            $q->where('warehouse_id', $warehouseId)
              ->whereIn('status', ['pending', 'confirmed', 'assigned']) // include pending to prevent overselling
              ->whereNull('deleted_at');
            if ($excludeOrderId) {
                $q->where('id', '!=', $excludeOrderId);
            }
        })
        ->where('product_id', $productId)
        ->sum(\DB::raw('COALESCE(quantity_confirmed, quantity_ordered)'));

        // Get reserved quantity from deliveries in preparing status (not yet started)
        $reservedFromDeliveries = \App\Models\DeliveryStock::whereHas('delivery', function ($q) use ($warehouseId) {
            $q->where('warehouse_id', $warehouseId)
              ->where('status', 'preparing')
              ->whereNull('deleted_at');
        })
        ->where('product_id', $productId)
        ->sum('quantity_loaded');

        $availableQty = $currentQty - (float) $reservedFromOrders - (float) $reservedFromDeliveries;

        return max(0, $availableQty);
    }

    public function scopeLowStock($query)
    {
        return $query->whereHas('product', function ($q) {
            $q->whereRaw('stock.quantity <= products.stock_alert');
        });
    }
}
