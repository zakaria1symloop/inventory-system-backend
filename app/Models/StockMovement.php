<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id',
        'warehouse_id',
        'user_id',
        'type',
        'reference',
        'movable_type',
        'movable_id',
        'quantity_before',
        'quantity_change',
        'quantity_after',
        'unit_cost',
        'note',
    ];

    protected $casts = [
        'quantity_before' => 'decimal:2',
        'quantity_change' => 'decimal:2',
        'quantity_after' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    // Type constants
    const TYPE_PURCHASE = 'purchase';
    const TYPE_PURCHASE_RETURN = 'purchase_return';
    const TYPE_SALE = 'sale';
    const TYPE_SALE_RETURN = 'sale_return';
    const TYPE_ADJUSTMENT = 'adjustment';
    const TYPE_TRANSFER = 'transfer';
    const TYPE_DELIVERY = 'delivery';
    const TYPE_DELIVERY_OUT = 'delivery_out';
    const TYPE_DELIVERY_RETURN = 'delivery_return';
    const TYPE_OPENING = 'opening';
    const TYPE_ORDER = 'order';

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function movable()
    {
        return $this->morphTo();
    }

    // Create movement and update stock atomically
    public static function record($productId, $warehouseId, $quantity, $type, $reference = null, $movable = null, $unitCost = null, $note = null)
    {
        $stock = Stock::firstOrCreate(
            ['product_id' => $productId, 'warehouse_id' => $warehouseId],
            ['quantity' => 0]
        );

        $quantityBefore = $stock->quantity;
        $quantityChange = in_array($type, [self::TYPE_PURCHASE, self::TYPE_SALE_RETURN, self::TYPE_DELIVERY_RETURN, self::TYPE_OPENING])
            || ($type === self::TYPE_ADJUSTMENT && $quantity > 0)
            ? abs($quantity)
            : -abs($quantity);

        $quantityAfter = $quantityBefore + $quantityChange;

        // Update stock
        $stock->quantity = $quantityAfter;
        $stock->save();

        // Create movement record
        $movement = self::create([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'user_id' => auth()->id(),
            'type' => $type,
            'reference' => $reference,
            'movable_type' => $movable ? get_class($movable) : null,
            'movable_id' => $movable ? $movable->id : null,
            'quantity_before' => $quantityBefore,
            'quantity_change' => $quantityChange,
            'quantity_after' => $quantityAfter,
            'unit_cost' => $unitCost,
            'note' => $note,
        ]);

        return $movement;
    }

    // Scopes
    public function scopeByProduct($query, $productId)
    {
        return $query->where('product_id', $productId);
    }

    public function scopeByWarehouse($query, $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeIncoming($query)
    {
        return $query->where('quantity_change', '>', 0);
    }

    public function scopeOutgoing($query)
    {
        return $query->where('quantity_change', '<', 0);
    }

    public function scopeDateRange($query, $from, $to)
    {
        if ($from) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to) {
            $query->whereDate('created_at', '<=', $to);
        }
        return $query;
    }

    // Get type label in Arabic
    public function getTypeLabelAttribute()
    {
        $labels = [
            self::TYPE_PURCHASE => 'شراء',
            self::TYPE_PURCHASE_RETURN => 'مرتجع شراء',
            self::TYPE_SALE => 'بيع',
            self::TYPE_SALE_RETURN => 'مرتجع بيع',
            self::TYPE_ADJUSTMENT => 'تعديل',
            self::TYPE_TRANSFER => 'نقل',
            self::TYPE_DELIVERY => 'توصيل',
            self::TYPE_DELIVERY_OUT => 'خروج للتوصيل',
            self::TYPE_DELIVERY_RETURN => 'مرتجع توصيل',
            self::TYPE_OPENING => 'رصيد افتتاحي',
            self::TYPE_ORDER => 'طلب',
        ];

        return $labels[$this->type] ?? $this->type;
    }

    // Check if incoming
    public function getIsIncomingAttribute()
    {
        return $this->quantity_change > 0;
    }
}
