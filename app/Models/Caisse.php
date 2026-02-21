<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Caisse extends Model
{
    protected $fillable = [
        'name',
        'user_id',
        'type',
        'balance',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function transactions()
    {
        return $this->hasMany(CaisseTransaction::class);
    }

    public function settlements()
    {
        return $this->hasMany(CaisseSettlement::class);
    }

    /**
     * Add a transaction to the caisse and update balance.
     */
    public function addTransaction(string $type, float $amount, ?string $sourceType = null, ?int $sourceId = null, ?string $description = null, ?int $createdBy = null): CaisseTransaction
    {
        if ($type === 'in') {
            $this->balance += $amount;
        } else {
            $this->balance -= $amount;
        }
        $this->save();

        return $this->transactions()->create([
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $this->balance,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'description' => $description,
            'created_by' => $createdBy,
        ]);
    }
}
