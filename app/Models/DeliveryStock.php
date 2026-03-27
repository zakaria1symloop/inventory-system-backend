<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryStock extends Model
{
    use HasFactory;

    protected $table = 'delivery_stock';

    protected $fillable = [
        'delivery_id',
        'product_id',
        'quantity_loaded',
        'quantity_delivered',
        'quantity_returned',
    ];

    protected $casts = [
        'quantity_loaded' => 'integer',
        'quantity_delivered' => 'integer',
        'quantity_returned' => 'integer',
    ];

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getRemainingQuantity()
    {
        return $this->quantity_loaded - $this->quantity_delivered - $this->quantity_returned;
    }

    public function recordDelivery($quantity)
    {
        $this->quantity_delivered += $quantity;
        $this->save();
    }

    public function recordReturn($quantity)
    {
        $this->quantity_returned += $quantity;
        $this->save();
    }
}
