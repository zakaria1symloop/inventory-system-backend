<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Delivery extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'livreur_id',
        'vehicle_id',
        'warehouse_id',
        'date',
        'start_time',
        'end_time',
        'status',
        'total_orders',
        'delivered_count',
        'failed_count',
        'total_amount',
        'collected_amount',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'total_orders' => 'integer',
        'delivered_count' => 'integer',
        'failed_count' => 'integer',
        'total_amount' => 'decimal:2',
        'collected_amount' => 'decimal:2',
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
        $prefix = 'DEL';
        $date = now()->format('Ymd');
        $last = self::whereDate('created_at', today())->latest()->first();
        $sequence = $last ? (int) substr($last->reference, -4) + 1 : 1;

        return $prefix . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function livreur()
    {
        return $this->belongsTo(User::class, 'livreur_id');
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function deliveryOrders()
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function returns()
    {
        return $this->hasMany(DeliveryReturn::class);
    }

    public function stock()
    {
        return $this->hasMany(DeliveryStock::class);
    }

    public function start()
    {
        $this->start_time = now();
        $this->status = 'in_progress';
        $this->save();
    }

    public function complete()
    {
        $this->end_time = now();
        $this->status = 'completed';
        $this->updateCounts();
        $this->save();
    }

    public function cancel()
    {
        $this->status = 'cancelled';
        $this->save();
    }

    public function updateCounts()
    {
        $this->total_orders = $this->deliveryOrders()->count();
        $this->delivered_count = $this->deliveryOrders()->whereIn('status', ['delivered', 'partial'])->count();
        $this->failed_count = $this->deliveryOrders()->whereIn('status', ['failed', 'postponed'])->count();

        // Recalculate totals based on actual amounts (after partial deliveries)
        $this->total_amount = $this->deliveryOrders()
            ->whereIn('status', ['delivered', 'partial'])
            ->sum('amount_due');
        $this->collected_amount = $this->deliveryOrders()->sum('amount_collected');

        $this->save();
    }

    public function calculateTotalAmount()
    {
        $this->total_amount = $this->deliveryOrders()->sum('amount_due');
        $this->save();
    }

    public function scopePreparing($query)
    {
        return $query->where('status', 'preparing');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
