<?php

namespace App\Models;

use App\Traits\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Purchase extends Model
{
    use HasFactory, SoftDeletes, GeneratesReference;

    protected static string $referencePrefix = 'PUR';

    protected $fillable = [
        'reference',
        'supplier_id',
        'warehouse_id',
        'user_id',
        'date',
        'total_amount',
        'discount',
        'tax',
        'shipping',
        'timbre',
        'timbre_percentage',
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
        'timbre' => 'decimal:2',
        'timbre_percentage' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'due_amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
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
        return $this->hasMany(PurchaseItem::class);
    }

    public function returns()
    {
        return $this->hasMany(PurchaseReturn::class);
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function calculateTotals()
    {
        // Calculate totals from items
        // quantity is now in pieces, so subtotal = unit_price × quantity - discount + tax
        $totalAmount = 0;
        foreach ($this->items()->get() as $item) {
            $subtotal = ($item->unit_price * $item->quantity) - $item->discount + $item->tax;
            if ($item->subtotal != $subtotal) {
                $item->subtotal = $subtotal;
                $item->save();
            }
            $totalAmount += $subtotal;
        }

        $this->total_amount = $totalAmount;

        // Recompute timbre from percentage on the AFTER-DISCOUNT base, so the
        // stamp tax never gets applied on the pre-discount amount even if the
        // client sent a stale figure.
        if ($this->timbre_percentage > 0) {
            $afterDiscount = max(0, $this->total_amount - $this->discount);
            $this->timbre = round($afterDiscount * ($this->timbre_percentage / 100), 2);
        }

        $this->grand_total = $this->total_amount - $this->discount + $this->tax + $this->shipping + $this->timbre;

        // Pending purchases have no real debt yet
        if ($this->status === 'pending') {
            $this->due_amount = 0;
            $this->paid_amount = 0;
            $this->payment_status = 'unpaid';
        } else {
            $this->due_amount = max(0, $this->grand_total - $this->paid_amount);
            $this->payment_status = $this->calculatePaymentStatus();
        }

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
