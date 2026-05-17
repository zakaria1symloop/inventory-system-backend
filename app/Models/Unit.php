<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Unit extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'short_name',
        'base_unit_id',
        'operator',
        'operation_value',
        'is_active',
    ];

    protected $casts = [
        'operation_value' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function baseUnit()
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    public function subUnits()
    {
        return $this->hasMany(Unit::class, 'base_unit_id');
    }

    public function productsAsBuyUnit()
    {
        return $this->hasMany(Product::class, 'unit_buy_id');
    }

    public function productsAsSaleUnit()
    {
        return $this->hasMany(Product::class, 'unit_sale_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBase($query)
    {
        return $query->whereNull('base_unit_id');
    }

    public function convertToBase($quantity)
    {
        if (!$this->base_unit_id) {
            return $quantity;
        }

        return $this->operator === '*'
            ? $quantity * $this->operation_value
            : $quantity / $this->operation_value;
    }

    public function convertFromBase($quantity)
    {
        if (!$this->base_unit_id) {
            return $quantity;
        }

        return $this->operator === '*'
            ? $quantity / $this->operation_value
            : $quantity * $this->operation_value;
    }

    /**
     * Walk up base_unit_id chain until reaching a root unit.
     */
    public function rootUnitId(): ?int
    {
        $current = $this;
        $seen = [];
        while ($current && $current->base_unit_id) {
            if (in_array($current->id, $seen)) break; // safety against cycles
            $seen[] = $current->id;
            $current = $current->baseUnit;
        }
        return $current?->id;
    }

    /**
     * Two units are compatible when they share the same root unit
     * (i.e. one is convertible into the other).
     */
    public static function compatible(?int $unitAId, ?int $unitBId): bool
    {
        if ($unitAId === null || $unitBId === null) {
            return true; // missing unit means we can't enforce — defer to other validation
        }
        if ($unitAId === $unitBId) {
            return true;
        }
        $a = self::find($unitAId);
        $b = self::find($unitBId);
        if (!$a || !$b) {
            return true;
        }
        return $a->rootUnitId() === $b->rootUnitId();
    }
}
