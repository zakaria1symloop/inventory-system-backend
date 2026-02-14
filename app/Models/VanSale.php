<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VanSale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'van_session_id',
        'client_id',
        'sale_time',
        'total_amount',
        'discount',
        'grand_total',
        'paid_amount',
        'due_amount',
        'payment_status',
        'latitude',
        'longitude',
        'notes',
    ];

    protected $casts = [
        'sale_time' => 'datetime',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->reference) {
                $model->reference = self::generateReference();
            }
            if (!$model->sale_time) {
                $model->sale_time = now();
            }
        });
    }

    public static function generateReference()
    {
        $prefix = 'VS';
        $date = now()->format('Ymd');
        $last = self::whereDate('created_at', today())->latest()->first();
        $sequence = $last ? (int) substr($last->reference, -4) + 1 : 1;

        return $prefix . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function vanSession()
    {
        return $this->belongsTo(VanSession::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function items()
    {
        return $this->hasMany(VanSaleItem::class);
    }

    public function calculateTotals()
    {
        $this->total_amount = $this->items()->sum('subtotal');
        $this->grand_total = $this->total_amount - $this->discount;
        $this->due_amount = $this->grand_total - $this->paid_amount;
        $this->payment_status = $this->calculatePaymentStatus();
        $this->save();
    }

    public function calculatePaymentStatus()
    {
        if ($this->paid_amount >= $this->grand_total) {
            return 'paid';
        } elseif ($this->paid_amount > 0) {
            return 'partial';
        }
        return 'unpaid';
    }
}
