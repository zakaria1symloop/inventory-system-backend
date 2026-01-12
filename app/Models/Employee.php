<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'phone',
        'position',
        'salary',
        'hire_date',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'hire_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function dispenses()
    {
        return $this->hasMany(Dispense::class);
    }

    public function getTotalDispensesAttribute()
    {
        return $this->dispenses()->sum('amount');
    }
}
