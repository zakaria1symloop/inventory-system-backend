<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class VanSession extends Model
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
        'total_loaded_value',
        'total_sales',
        'total_collected',
        'total_credit',
        'total_returned_value',
        'sales_count',
        'notes',
    ];

    protected $casts = [
        'date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'total_loaded_value' => 'decimal:2',
        'total_sales' => 'decimal:2',
        'total_collected' => 'decimal:2',
        'total_credit' => 'decimal:2',
        'total_returned_value' => 'decimal:2',
        'sales_count' => 'integer',
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
        $prefix = 'VAN';
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

    public function items()
    {
        return $this->hasMany(VanSessionItem::class);
    }

    public function sales()
    {
        return $this->hasMany(VanSale::class);
    }

    public function returns()
    {
        return $this->hasMany(VanReturn::class);
    }

    public function start()
    {
        $this->start_time = now();
        $this->status = 'active';
        $this->save();
    }

    public function complete()
    {
        $this->end_time = now();
        $this->status = 'completed';
        $this->updateTotals();
        $this->save();
    }

    public function cancel()
    {
        $this->status = 'cancelled';
        $this->save();
    }

    public function updateTotals()
    {
        $this->sales_count = $this->sales()->count();
        $this->total_sales = $this->sales()->sum('grand_total');
        $this->total_collected = $this->sales()->sum('paid_amount');
        $this->total_credit = $this->sales()->sum('due_amount');

        // Calculate loaded value
        $loadedValue = 0;
        foreach ($this->items()->with('product')->get() as $item) {
            $loadedValue += $item->quantity_loaded * ($item->product->retail_price ?? 0);
        }
        $this->total_loaded_value = $loadedValue;

        // Calculate returned value
        $returnedValue = 0;
        foreach ($this->returns()->with('product')->get() as $return) {
            $returnedValue += $return->quantity * ($return->product->retail_price ?? 0);
        }
        $this->total_returned_value = $returnedValue;

        $this->save();
    }

    public function calculateLoadedValue()
    {
        $value = 0;
        foreach ($this->items()->with('product')->get() as $item) {
            $value += $item->quantity_loaded * ($item->product->retail_price ?? 0);
        }
        $this->total_loaded_value = $value;
        $this->save();
    }

    public function getAvailableStock($productId)
    {
        $item = $this->items()->where('product_id', $productId)->first();
        if (!$item) return 0;
        return round($item->quantity_loaded - $item->quantity_sold - $item->quantity_returned, 2);
    }

    public function scopePreparing($query)
    {
        return $query->where('status', 'preparing');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }
}
