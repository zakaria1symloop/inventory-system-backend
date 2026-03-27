<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tenant extends Model
{
    protected $connection = 'mysql';

    protected $fillable = [
        'name',
        'database_name',
        'plan',
        'product_limit',
        'user_limit',
        'is_active',
        'otp_required',
        'updates_enabled',
        'app_version',
        'features',
        'last_updated_at',
        'trial_ends_at',
        'deactivate_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'otp_required' => 'boolean',
            'updates_enabled' => 'boolean',
            'trial_ends_at' => 'datetime',
            'last_updated_at' => 'datetime',
            'product_limit' => 'integer',
            'user_limit' => 'integer',
            'features' => 'array',
            'deactivate_at' => 'datetime',
        ];
    }

    public static function planLimits(): array
    {
        return [
            'free'       => ['products' => 25,    'users' => 1],
            'starter'    => ['products' => 100,   'users' => 1],      // legacy
            'pro'        => ['products' => 2000,  'users' => 10],
            'pro_ai'     => ['products' => 5000,  'users' => 20],
            'business'   => ['products' => 2000,  'users' => 10],     // legacy → maps to pro
            'enterprise' => ['products' => 99999, 'users' => 999],
        ];
    }

    public static function planPrices(): array
    {
        return [
            'free'       => 0,
            'starter'    => 0,       // legacy
            'pro'        => 10900,
            'pro_ai'     => 16900,
            'business'   => 10900,   // legacy → maps to pro
            'enterprise' => 0,       // custom pricing
        ];
    }

    public static function extraUserPrice(): array
    {
        return [
            'free'       => 0,
            'starter'    => 0,
            'pro'        => 1000,
            'pro_ai'     => 1000,
            'business'   => 1000,
            'enterprise' => 0,
        ];
    }

    public function getEnabledFeatures(): array
    {
        if (!empty($this->features)) {
            return $this->features;
        }

        return self::getDefaultFeatures($this->plan);
    }

    public function hasFeature(string $key): bool
    {
        return in_array($key, $this->getEnabledFeatures());
    }

    public static function getDefaultFeatures(string $plan): array
    {
        return config("features.plan_defaults.{$plan}", config('features.plan_defaults.free', []));
    }

    public function emailMaps()
    {
        return $this->hasMany(TenantEmailMap::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(Subscription::class);
    }

    public function activeSubscription()
    {
        return $this->hasOne(Subscription::class)->where('status', 'active')->latest();
    }

    public function updateLogs()
    {
        return $this->hasMany(TenantUpdateLog::class);
    }
}
