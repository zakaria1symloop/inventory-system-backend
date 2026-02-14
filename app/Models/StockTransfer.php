<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'from_warehouse_id',
        'to_warehouse_id',
        'created_by',
        'collected_by',
        'status',
        'collected_at',
        'notes',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
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
        $prefix = 'TRF';
        $date = now()->format('Ymd');
        $last = self::whereDate('created_at', today())->latest()->first();
        $sequence = $last ? (int) substr($last->reference, -4) + 1 : 1;

        return $prefix . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isCollected()
    {
        return $this->status === 'collected';
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeCollected($query)
    {
        return $query->where('status', 'collected');
    }

    public function scopeForWarehouse($query, $warehouseId)
    {
        return $query->where('to_warehouse_id', $warehouseId);
    }
}
