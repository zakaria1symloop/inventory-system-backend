<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
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
        'client_category_id',
        'created_by',
        'source',
        'warehouse_id',
        'copied_from',
    ];

    protected $casts = [
        'gps_lat' => 'decimal:8',
        'gps_lng' => 'decimal:8',
        'credit_limit' => 'decimal:2',
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->code)) {
                $model->code = static::generateCode();
            }
        });
    }

    public static function generateCode(): string
    {
        $prefix = 'CLT';
        $separator = '-';
        $date = now()->format('Ymd');
        $pattern = $prefix . $separator . $date . $separator;

        $last = DB::table('clients')
            ->where('code', 'like', $pattern . '%')
            ->orderByRaw('CAST(SUBSTRING(code, -4) AS UNSIGNED) DESC')
            ->lockForUpdate()
            ->first();

        $sequence = $last ? (int) substr($last->code, -4) + 1 : 1;

        return $pattern . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function originalClient()
    {
        return $this->belongsTo(Client::class, 'copied_from');
    }

    public function copies()
    {
        return $this->hasMany(Client::class, 'copied_from');
    }

    public function clientCategory()
    {
        return $this->belongsTo(ClientCategory::class);
    }

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
