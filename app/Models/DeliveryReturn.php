<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'order_id',
        'product_id',
        'quantity',
        'reason',
        'returnable_to_stock',
        'unit_cost',
        'loss_amount',
        'loss_recorded',
        'notes',
        'processed',
        'processed_at',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'loss_amount' => 'decimal:2',
        'returnable_to_stock' => 'boolean',
        'loss_recorded' => 'boolean',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    // Reasons that allow returning to stock
    const RETURNABLE_REASONS = ['refused', 'excess', 'store_closed', 'wrong'];
    // Reasons that are losses (damaged, destroyed)
    const LOSS_REASONS = ['damaged'];

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Determine if this return should go back to stock based on reason
     */
    public static function isReturnableReason($reason)
    {
        return in_array($reason, self::RETURNABLE_REASONS);
    }

    /**
     * Determine if this return is a loss (damaged products)
     */
    public static function isLossReason($reason)
    {
        return in_array($reason, self::LOSS_REASONS);
    }

    /**
     * Process the return - either add back to stock or record as loss
     */
    public function process($warehouseId)
    {
        if ($this->processed) {
            return; // Already processed
        }

        // Update order item returned quantity
        $orderItem = $this->order->items()->where('product_id', $this->product_id)->first();
        if ($orderItem) {
            $orderItem->quantity_returned += $this->quantity;
            $orderItem->save();
        }

        if ($this->returnable_to_stock) {
            // Add stock back to warehouse and record movement
            StockMovement::record(
                $this->product_id,
                $warehouseId,
                $this->quantity,
                StockMovement::TYPE_DELIVERY_RETURN,
                $this->delivery->reference,
                $this->delivery,
                null,
                'مرتجع توصيل: ' . $this->getReasonLabel()
            );
        } else {
            // This is a loss - record it
            $this->recordLoss();
        }

        $this->processed = true;
        $this->processed_at = now();
        $this->save();
    }

    /**
     * Record the loss for damaged/destroyed products
     */
    public function recordLoss()
    {
        if ($this->loss_recorded) {
            return;
        }

        // Calculate loss amount if not set
        if ($this->loss_amount <= 0) {
            $product = $this->product;
            $this->unit_cost = $product->cost_price ?? 0;
            $this->loss_amount = $this->unit_cost * $this->quantity;
        }

        // Create a dispense/expense record for the loss
        if ($this->loss_amount > 0) {
            Dispense::create([
                'reference' => 'LOSS-' . $this->delivery->reference . '-' . $this->id,
                'category' => 'loss',
                'amount' => $this->loss_amount,
                'date' => now(),
                'description' => sprintf(
                    'خسارة بضاعة تالفة - %s (الكمية: %s) من التوصيل %s',
                    $this->product->name ?? 'منتج',
                    $this->quantity,
                    $this->delivery->reference
                ),
                'user_id' => auth()->id(),
            ]);
        }

        $this->loss_recorded = true;
        $this->save();
    }

    public function getReasonLabel()
    {
        $labels = [
            'refused' => 'مرفوض',
            'damaged' => 'تالف',
            'excess' => 'زيادة',
            'store_closed' => 'المحل مغلق',
            'wrong' => 'خطأ',
            'other' => 'أخرى',
        ];

        return $labels[$this->reason] ?? $this->reason;
    }

    public function scopeUnprocessed($query)
    {
        return $query->where('processed', false);
    }

    public function scopeProcessed($query)
    {
        return $query->where('processed', true);
    }

    public function scopeByReason($query, $reason)
    {
        return $query->where('reason', $reason);
    }

    public function scopeDamaged($query)
    {
        return $query->whereIn('reason', self::LOSS_REASONS);
    }

    public function scopeReturnable($query)
    {
        return $query->whereIn('reason', self::RETURNABLE_REASONS);
    }
}
