<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VanReturn extends Model
{
    use HasFactory;

    protected $fillable = [
        'van_session_id',
        'product_id',
        'quantity',
        'reason',
        'returnable_to_stock',
        'processed',
        'processed_at',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'returnable_to_stock' => 'boolean',
        'processed' => 'boolean',
        'processed_at' => 'datetime',
    ];

    public function vanSession()
    {
        return $this->belongsTo(VanSession::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getReasonLabelAttribute()
    {
        $labels = [
            'unsold' => 'غير مباع',
            'damaged' => 'تالف',
            'expired' => 'منتهي الصلاحية',
            'other' => 'أخرى',
        ];
        return $labels[$this->reason] ?? $this->reason;
    }
}
