<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaasPayment extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id',
        'plan_from',
        'plan_to',
        'amount',
        'currency',
        'status',
        'gateway_invoice_id',
        'gateway_checkout_url',
        'gateway_response',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'gateway_response' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
