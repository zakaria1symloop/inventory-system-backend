<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'avatar',
        'role',
        'is_active',
        'can_collect_debt',
        'latitude',
        'longitude',
        'last_location_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'can_collect_debt' => 'boolean',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'last_location_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isManager(): bool
    {
        return $this->role === 'manager';
    }

    public function isSeller(): bool
    {
        return $this->role === 'seller';
    }

    public function isLivreur(): bool
    {
        return $this->role === 'livreur';
    }

    public function isCashvan(): bool
    {
        return $this->role === 'cashvan';
    }

    public function trips()
    {
        return $this->hasMany(Trip::class, 'seller_id');
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class, 'livreur_id');
    }

    public function orders()
    {
        return $this->hasMany(Order::class, 'seller_id');
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function adjustments()
    {
        return $this->hasMany(Adjustment::class);
    }

    public function syncLogs()
    {
        return $this->hasMany(SyncLog::class);
    }

    public function caisse()
    {
        return $this->hasOne(Caisse::class);
    }

    protected static function booted(): void
    {
        static::created(function (User $user) {
            $typeMap = [
                'seller' => 'vendeur',
                'livreur' => 'livreur',
                'cashvan' => 'cashvan',
                'admin' => 'principale',
            ];

            if (isset($typeMap[$user->role])) {
                Caisse::create([
                    'user_id' => $user->id,
                    'type' => $typeMap[$user->role],
                ]);
            }
        });
    }
}
