<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'quantity_ordered',
        'quantity_confirmed',
        'quantity_delivered',
        'quantity_returned',
        'unit_price',
        'discount',
        'subtotal',
        'notes',
    ];

    protected $casts = [
        'quantity_ordered' => 'integer',
        'quantity_confirmed' => 'integer',
        'quantity_delivered' => 'integer',
        'quantity_returned' => 'integer',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        // Subtotal = unit_price (per piece) × quantity_ordered (pieces) - discount
        static::creating(function ($model) {
            // Default quantity_confirmed to quantity_ordered if not set
            if ($model->quantity_confirmed === null) {
                $model->quantity_confirmed = $model->quantity_ordered;
            }
            $model->subtotal = ($model->unit_price * $model->quantity_ordered) - $model->discount;
        });

        static::updating(function ($model) {
            $model->subtotal = ($model->unit_price * $model->quantity_ordered) - $model->discount;
        });
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getPendingQuantity()
    {
        return $this->quantity_confirmed - $this->quantity_delivered - $this->quantity_returned;
    }
}
