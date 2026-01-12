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
        'quantity_ordered' => 'decimal:2',
        'quantity_confirmed' => 'decimal:2',
        'quantity_delivered' => 'decimal:2',
        'quantity_returned' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        // Subtotal = unit_price (per piece) × pieces_per_package × quantity - discount
        static::creating(function ($model) {
            // Default quantity_confirmed to quantity_ordered if not set
            if ($model->quantity_confirmed === null) {
                $model->quantity_confirmed = $model->quantity_ordered;
            }
            $piecesPerPackage = $model->product->pieces_per_package ?? 1;
            $model->subtotal = ($model->unit_price * $piecesPerPackage * $model->quantity_ordered) - $model->discount;
        });

        static::updating(function ($model) {
            $piecesPerPackage = $model->product->pieces_per_package ?? 1;
            $model->subtotal = ($model->unit_price * $piecesPerPackage * $model->quantity_ordered) - $model->discount;
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
