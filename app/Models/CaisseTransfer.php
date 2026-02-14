<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaisseTransfer extends Model
{
    protected $fillable = [
        'from_caisse_id',
        'to_caisse_id',
        'amount',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function fromCaisse()
    {
        return $this->belongsTo(Caisse::class, 'from_caisse_id');
    }

    public function toCaisse()
    {
        return $this->belongsTo(Caisse::class, 'to_caisse_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
