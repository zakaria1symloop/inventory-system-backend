<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaisseTransaction extends Model
{
    protected $fillable = [
        'caisse_id',
        'type',
        'amount',
        'balance_after',
        'source_type',
        'source_id',
        'description',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function caisse()
    {
        return $this->belongsTo(Caisse::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
