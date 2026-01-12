<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'category_id',
        'brand_id',
        'unit_buy_id',
        'unit_sale_id',
        'barcode',
        'description',
        'product_unit',
        'pieces_per_package',
        'stock_alert',
        'cost_price',
        'retail_price',
        'wholesale_price',
        'min_selling_price',
        'tax_percent',
        'tax_type',
        'discount_type',
        'discount_value',
        'points',
        'opening_stock',
        'image',
        'is_active',
    ];

    protected $casts = [
        'stock_alert' => 'integer',
        'cost_price' => 'decimal:2',
        'retail_price' => 'decimal:2',
        'wholesale_price' => 'decimal:2',
        'min_selling_price' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'discount_value' => 'decimal:2',
        'points' => 'integer',
        'opening_stock' => 'decimal:2',
        'pieces_per_package' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = ['current_stock'];

    public function getCurrentStockAttribute()
    {
        return $this->stock()->sum('quantity');
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function unitBuy()
    {
        return $this->belongsTo(Unit::class, 'unit_buy_id');
    }

    public function unitSale()
    {
        return $this->belongsTo(Unit::class, 'unit_sale_id');
    }

    public function stock()
    {
        return $this->hasMany(Stock::class);
    }

    public function purchaseItems()
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function saleItems()
    {
        return $this->hasMany(SaleItem::class);
    }

    public function orderItems()
    {
        return $this->hasMany(OrderItem::class);
    }

    public function adjustmentItems()
    {
        return $this->hasMany(AdjustmentItem::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeLowStock($query)
    {
        return $query->whereHas('stock', function ($q) {
            $q->whereRaw('quantity <= products.stock_alert');
        });
    }

    public function getStockInWarehouse($warehouseId)
    {
        return $this->stock()->where('warehouse_id', $warehouseId)->first()?->quantity ?? 0;
    }

    public function getTotalStock()
    {
        return $this->stock()->sum('quantity');
    }

    public static function generateBarcode()
    {
        do {
            $barcode = '200' . Str::padLeft(random_int(0, 9999999999), 10, '0');
        } while (self::where('barcode', $barcode)->exists());

        return $barcode;
    }

    public function calculatePriceWithTax($price)
    {
        if ($this->tax_type === 'exclusive') {
            return $price + ($price * $this->tax_percent / 100);
        }
        return $price;
    }

    public function calculateDiscount($price)
    {
        if ($this->discount_type === 'percent') {
            return $price * $this->discount_value / 100;
        }
        return $this->discount_value;
    }
}
