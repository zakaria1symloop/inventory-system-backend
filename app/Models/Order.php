<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'trip_id',
        'client_id',
        'seller_id',
        'warehouse_id',
        'date',
        'total_amount',
        'discount',
        'tax',
        'grand_total',
        'status',
        'payment_status',
        'payment_method',
        'notes',
        'has_problem',
        'problem_description',
        'problem_reported_at',
        'problem_reported_by',
    ];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'has_problem' => 'boolean',
        'problem_reported_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->reference) {
                $model->reference = self::generateReference();
            }
        });
    }

    public static function generateReference()
    {
        $prefix = 'ORD';
        $date = now()->format('Ymd');
        $last = self::whereDate('created_at', today())->latest()->first();
        $sequence = $last ? (int) substr($last->reference, -4) + 1 : 1;

        return $prefix . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function deliveryOrders()
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function deliveryReturns()
    {
        return $this->hasMany(DeliveryReturn::class);
    }

    public function problemReporter()
    {
        return $this->belongsTo(User::class, 'problem_reported_by');
    }

    public function reportProblem($description, $userId)
    {
        $this->has_problem = true;
        $this->problem_description = $description;
        $this->problem_reported_at = now();
        $this->problem_reported_by = $userId;
        $this->save();
    }

    public function resolveProblem()
    {
        $this->has_problem = false;
        $this->problem_description = null;
        $this->problem_reported_at = null;
        $this->problem_reported_by = null;
        $this->save();
    }

    public function scopeWithProblems($query)
    {
        return $query->where('has_problem', true);
    }

    public function calculateTotals()
    {
        $this->total_amount = $this->items->sum('subtotal');
        $this->grand_total = $this->total_amount - $this->discount + $this->tax;
        $this->save();
    }

    public function confirm()
    {
        $this->status = 'confirmed';
        foreach ($this->items as $item) {
            $item->quantity_confirmed = $item->quantity_ordered;
            $item->save();
        }
        $this->save();
    }

    public function assignToDelivery()
    {
        $this->status = 'assigned';
        $this->save();
    }

    public function markDelivered()
    {
        $this->status = 'delivered';
        foreach ($this->items as $item) {
            $item->quantity_delivered = $item->quantity_confirmed;
            $item->save();
        }
        $this->save();
    }

    public function markPartial()
    {
        $this->status = 'partial';
        $this->save();
    }

    public function cancel()
    {
        $this->status = 'cancelled';
        $this->save();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', 'assigned');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }
}
