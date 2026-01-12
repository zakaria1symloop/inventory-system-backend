<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'device_id',
        'entity_type',
        'entity_id',
        'action',
        'data',
        'synced_at',
        'status',
    ];

    protected $casts = [
        'data' => 'array',
        'synced_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function markSynced()
    {
        $this->status = 'synced';
        $this->synced_at = now();
        $this->save();
    }

    public function markFailed()
    {
        $this->status = 'failed';
        $this->save();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeSynced($query)
    {
        return $query->where('status', 'synced');
    }

    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('device_id', $deviceId);
    }

    public function scopeForEntity($query, $entityType, $entityId = null)
    {
        $query->where('entity_type', $entityType);
        if ($entityId) {
            $query->where('entity_id', $entityId);
        }
        return $query;
    }
}
