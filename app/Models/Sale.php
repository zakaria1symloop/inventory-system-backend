<?php

namespace App\Models;

use App\Traits\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes, GeneratesReference;

    protected static string $referencePrefix = 'SAL';

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
        'timbre',
        'timbre_percentage',
        'grand_total',
        'paid_amount',
        'due_amount',
        'status',
        'payment_status',
        'note',
        'source',
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

    protected $appends = ['total_cost'];

    public function getTotalCostAttribute()
    {
        return $this->items->sum(function ($item) {
            return ($item->cost_price ?? 0) * $item->quantity;
        });
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

        // Recompute timbre from percentage on the AFTER-DISCOUNT base.
        if ($this->timbre_percentage > 0) {
            $afterDiscount = max(0, $this->total_amount - $this->discount);
            $this->timbre = round($afterDiscount * ($this->timbre_percentage / 100), 2);
        }

        $this->grand_total = $this->total_amount - $this->discount + $this->tax + $this->shipping + $this->timbre;

        // Draft sales have no real debt yet
        if ($this->status === 'draft') {
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

    protected static function booted()
    {
        // Mirror the sale's payment_status onto the sales-order it was delivered
        // for, so the seller/orders apps never show a paid delivery as
        // "غير مدفوع". Fires for EVERY payment path that saves the Sale —
        // delivery, livreur debt collection, dashboard payment, client payment —
        // not just at delivery time. (Delivery-time sync is also done explicitly
        // in DeliveryController, since the sale↔order link is set after the last
        // save there.)
        static::saved(function (Sale $sale) {
            $sale->syncOrderPaymentStatus();
        });
    }

    /**
     * Keep the originating sales-order(s) in lockstep with this sale's
     * payment_status. A delivery sale links back via delivery_orders.sale_id →
     * order_id. No-op for sales not tied to a delivered order (e.g. plain POS).
     */
    public function syncOrderPaymentStatus(): void
    {
        $orderIds = DeliveryOrder::where('sale_id', $this->id)
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->unique();

        if ($orderIds->isEmpty()) {
            return;
        }

        Order::whereIn('id', $orderIds)
            ->where('payment_status', '!=', $this->payment_status)
            ->update(['payment_status' => $this->payment_status]);
    }
}
