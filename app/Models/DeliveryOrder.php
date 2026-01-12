<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DeliveryOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'delivery_id',
        'order_id',
        'client_id',
        'delivery_order',
        'status',
        'amount_due',
        'amount_collected',
        'delivered_at',
        'attempted_at',
        'failure_reason',
        'notes',
    ];

    protected $casts = [
        'delivery_order' => 'integer',
        'amount_due' => 'decimal:2',
        'amount_collected' => 'decimal:2',
        'delivered_at' => 'datetime',
        'attempted_at' => 'datetime',
    ];

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function markDelivered()
    {
        $this->status = 'delivered';
        $this->delivered_at = now();
        $this->save();

        $this->order->markDelivered();
    }

    public function markPartial($returnedItems = [])
    {
        $this->status = 'partial';
        $this->delivered_at = now();
        $this->save();

        $this->order->markPartial();
    }

    public function markFailed($reason = null)
    {
        $this->status = 'failed';
        $this->attempted_at = now();
        $this->failure_reason = $reason;
        $this->save();
    }

    public function postpone($notes = null)
    {
        $this->status = 'postponed';
        $this->attempted_at = now();
        $this->notes = $notes;
        $this->save();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'postponed']);
    }
}
