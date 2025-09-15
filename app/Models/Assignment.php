<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Assignment extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $fillable = [
        'asset_id',
        'user_id', 
        'start_at',
        'end_at',
        'status',
        'notes',
        'color'
    ];
    protected $table = 'assignments';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => 'string',
    ];

    // Validation rules for the model
    public static $rules = [
        'asset_id' => 'required|exists:assets,id',
        'user_id' => 'required|exists:users,id',
        'start_at' => 'required|date',
        'end_at' => 'nullable|date|after:start_at',
        'status' => 'required|in:active,completed,cancelled,overdue',
        'notes' => 'nullable|string|max:1000',
    ];

    // Relationships
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function section()
    {
        return $this->hasOneThrough(Section::class, Asset::class, 'id', 'id', 'asset_id', 'section_id');
    }

    public function room()
    {
        return $this->hasOneThrough(Room::class, Asset::class, 'id', 'id', 'asset_id', 'section_id')
                    ->join('sections', 'sections.room_id', '=', 'rooms.id')
                    ->where('sections.id', '=', 'assets.section_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByAsset($query, $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeOverlapping($query, $assetId, $startAt, $endAt, $excludeId = null)
    {
        $query->where('asset_id', $assetId)
              ->where('status', '!=', 'cancelled')
              ->where(function ($q) use ($startAt, $endAt) {
                  $q->whereBetween('start_at', [$startAt, $endAt])
                    ->orWhereBetween('end_at', [$startAt, $endAt])
                    ->orWhere(function ($q2) use ($startAt, $endAt) {
                        $q2->where('start_at', '<=', $startAt)
                            ->where('end_at', '>=', $endAt);
                    });
              });

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        return $query;
    }
}
