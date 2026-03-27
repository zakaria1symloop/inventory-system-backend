<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class VanSessionItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'van_session_id',
        'product_id',
        'quantity_loaded',
        'quantity_sold',
        'quantity_returned',
        'unit_cost',
    ];

    protected $casts = [
        'quantity_loaded' => 'integer',
        'quantity_sold' => 'integer',
        'quantity_returned' => 'integer',
        'unit_cost' => 'decimal:2',
    ];

    public function vanSession()
    {
        return $this->belongsTo(VanSession::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function getAvailableQuantityAttribute()
    {
        return $this->quantity_loaded - $this->quantity_sold - $this->quantity_returned;
    }
}
