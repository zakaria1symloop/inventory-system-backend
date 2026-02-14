<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductRequest extends Model
{
    protected $fillable = [
        'reference',
        'van_session_id',
        'requested_by',
        'warehouse_id',
        'status',
        'notes',
        'admin_notes',
        'processed_by',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->reference)) {
                $today = now()->format('Ymd');
                $count = static::whereDate('created_at', now()->toDateString())->count() + 1;
                $model->reference = 'PR' . $today . str_pad($count, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function vanSession(): BelongsTo
    {
        return $this->belongsTo(VanSession::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductRequestItem::class);
    }
}
