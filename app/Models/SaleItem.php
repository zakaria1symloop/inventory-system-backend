<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class SaleItem extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sale_id',
        'product_id',
        'quantity',
        'unit_price',
        'discount',
        'tax',
        'subtotal',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        // Subtotal = unit_price (per piece) × pieces_per_package × quantity - discount + tax
        static::creating(function ($model) {
            $piecesPerPackage = $model->product->pieces_per_package ?? 1;
            $model->subtotal = ($model->unit_price * $piecesPerPackage * $model->quantity) - $model->discount + $model->tax;
        });

        static::updating(function ($model) {
            $piecesPerPackage = $model->product->pieces_per_package ?? 1;
            $model->subtotal = ($model->unit_price * $piecesPerPackage * $model->quantity) - $model->discount + $model->tax;
        });
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
