<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TenantEmailMap extends Model
{
    protected $connection = 'mysql';

    protected $table = 'tenant_email_map';

    protected $fillable = [
        'email',
        'phone',
        'tenant_id',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }
}
