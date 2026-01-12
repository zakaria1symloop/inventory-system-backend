<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TripStore extends Model
{
    use HasFactory;

    protected $fillable = [
        'trip_id',
        'client_id',
        'visit_order',
        'visited_at',
        'status',
        'notes',
    ];

    protected $casts = [
        'visit_order' => 'integer',
        'visited_at' => 'datetime',
    ];

    public function trip()
    {
        return $this->belongsTo(Trip::class);
    }

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function markVisited()
    {
        $this->visited_at = now();
        $this->status = 'visited';
        $this->save();
    }

    public function skip($notes = null)
    {
        $this->status = 'skipped';
        $this->notes = $notes;
        $this->save();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeVisited($query)
    {
        return $query->where('status', 'visited');
    }
}
