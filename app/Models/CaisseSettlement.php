<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaisseSettlement extends Model
{
    protected $fillable = [
        'caisse_id',
        'amount',
        'type',
        'balance_before',
        'balance_after',
        'notes',
        'settled_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function caisse()
    {
        return $this->belongsTo(Caisse::class);
    }

    public function settler()
    {
        return $this->belongsTo(User::class, 'settled_by');
    }
}
