<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'address',
        'gps_lat',
        'gps_lng',
        'credit_limit',
        'balance',
        'is_active',
        'rc',
        'nif',
        'ai',
        'nis',
        'rib',
    ];

    protected $casts = [
        'gps_lat' => 'decimal:8',
        'gps_lng' => 'decimal:8',
        'credit_limit' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function saleReturns()
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function tripStores()
    {
        return $this->hasMany(TripStore::class);
    }

    public function deliveryOrders()
    {
        return $this->hasMany(DeliveryOrder::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function hasCredit()
    {
        return $this->balance < $this->credit_limit;
    }

    public function availableCredit()
    {
        return max(0, $this->credit_limit - $this->balance);
    }

    public function updateBalance($amount, $operation = 'add')
    {
        if ($operation === 'add') {
            $this->balance += $amount;
        } else {
            $this->balance -= $amount;
        }
        $this->save();
    }
}
