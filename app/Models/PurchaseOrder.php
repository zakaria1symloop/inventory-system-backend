<?php

namespace App\Models;

use App\Traits\GeneratesReference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasFactory, GeneratesReference;

    protected static string $referencePrefix = 'BC';
    protected static string $referenceSeparator = '-';

    protected $fillable = [
        'reference',
        'supplier_id',
        'warehouse_id',
        'user_id',
        'date',
        'expected_delivery_date',
        'total_amount',
        'discount',
        'tax',
        'shipping',
        'grand_total',
        'status',
        'note',
        'terms',
        'purchase_id',
    ];

    protected $casts = [
        'date' => 'date',
        'expected_delivery_date' => 'date',
        'total_amount' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'shipping' => 'decimal:2',
        'grand_total' => 'decimal:2',
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
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }

    public function calculateTotals()
    {
        // unit_price is price per 1 piece, multiply by pieces_per_package
        $totalAmount = $this->items()->with('product')->get()->sum(function ($item) {
            $piecesPerPackage = $item->product->pieces_per_package ?? 1;
            // subtotal = unit_price × pieces_per_package × quantity - discount + tax
            return ($item->unit_price * $piecesPerPackage * $item->quantity) - $item->discount + $item->tax;
        });

        $this->total_amount = $totalAmount;
        $this->grand_total = $totalAmount - $this->discount + $this->tax + $this->shipping;
        $this->save();
    }
}
