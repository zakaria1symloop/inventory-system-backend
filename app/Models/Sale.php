<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference',
        'client_id',
        'warehouse_id',
        'user_id',
        'date',
        'total_amount',
        'discount',
        'tax',
        'shipping',
        'grand_total',
        'paid_amount',
        'due_amount',
        'status',
        'payment_status',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
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
        $prefix = 'SAL';
        $date = now()->format('Ymd');
        $last = self::whereDate('created_at', today())->latest()->first();
        $sequence = $last ? (int) substr($last->reference, -4) + 1 : 1;

        return $prefix . $date . str_pad($sequence, 4, '0', STR_PAD_LEFT);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function returns()
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function calculateTotals()
    {
        // Calculate totals from items
        // unit_price is price per 1 piece, multiply by pieces_per_package
        $totalAmount = 0;
        foreach ($this->items()->with('product')->get() as $item) {
            $piecesPerPackage = $item->product->pieces_per_package ?? 1;
            // subtotal = unit_price × pieces_per_package × quantity - discount + tax
            $subtotal = ($item->unit_price * $piecesPerPackage * $item->quantity) - $item->discount + $item->tax;
            if ($item->subtotal != $subtotal) {
                $item->subtotal = $subtotal;
                $item->save();
            }
            $totalAmount += $subtotal;
        }

        $this->total_amount = $totalAmount;
        $this->grand_total = $this->total_amount - $this->discount + $this->tax + $this->shipping;
        $this->due_amount = $this->grand_total - $this->paid_amount;
        $this->payment_status = $this->calculatePaymentStatus();
        $this->save();
    }

    public function calculatePaymentStatus()
    {
        if ($this->paid_amount >= $this->grand_total) {
            return 'paid';
        }
        if ($this->paid_amount > 0) {
            return 'partial';
        }
        return 'unpaid';
    }
}
