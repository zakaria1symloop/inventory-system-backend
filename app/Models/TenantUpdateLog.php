<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantUpdateLog extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'tenant_id',
        'from_version',
        'to_version',
        'status',
        'migrations_run',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'migrations_run' => 'array',
        ];
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
