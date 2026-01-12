<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Dispense extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'employee_id',
        'user_id',
        'date',
        'category',
        'amount',
        'description',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($dispense) {
            if (empty($dispense->reference)) {
                $lastDispense = static::orderBy('id', 'desc')->first();
                $nextId = $lastDispense ? $lastDispense->id + 1 : 1;
                $dispense->reference = 'DIS-' . str_pad($nextId, 6, '0', STR_PAD_LEFT);
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public static function getCategories()
    {
        return [
            'salary' => 'راتب',
            'advance' => 'سلفة',
            'transport' => 'نقل',
            'maintenance' => 'صيانة',
            'supplies' => 'مستلزمات',
            'utilities' => 'فواتير',
            'rent' => 'إيجار',
            'other' => 'أخرى',
        ];
    }
}
