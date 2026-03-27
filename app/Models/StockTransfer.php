<?php

namespace App\Models;

use App\Traits\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockTransfer extends Model
{
    use HasFactory, SoftDeletes, GeneratesReference;

    protected static string $referencePrefix = 'TRF';

    // Statuses: pending (order) → loading (chargement) → collected (start)
    const STATUS_PENDING = 'pending';
    const STATUS_LOADING = 'loading';
    const STATUS_COLLECTED = 'collected';

    protected $fillable = [
        'reference',
        'from_warehouse_id',
        'to_warehouse_id',
        'created_by',
        'collected_by',
        'approved_by',
        'status',
        'collected_at',
        'approved_at',
        'notes',
    ];

    protected $casts = [
        'collected_at' => 'datetime',
        'approved_at' => 'datetime',
    ];

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

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function isPending()
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isLoading()
    {
        return $this->status === self::STATUS_LOADING;
    }

    public function isCollected()
    {
        return $this->status === self::STATUS_COLLECTED;
    }

    public function scopePending($query)
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeLoading($query)
    {
        return $query->where('status', self::STATUS_LOADING);
    }

    public function scopeCollected($query)
    {
        return $query->where('status', self::STATUS_COLLECTED);
    }

    public function scopeForWarehouse($query, $warehouseId)
    {
        return $query->where('to_warehouse_id', $warehouseId);
    }
}
