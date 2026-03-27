<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'driver_apk_url',
        'driver_apk_version',
        'sales_apk_url',
        'sales_apk_version',
        'cashvan_apk_url',
        'cashvan_apk_version',
    ];
}
