<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Appointment extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'asset_id',
        'specialist_id',
        'start_at',
        'end_at',
        'notes',
        'color',
    ];

    protected $table = 'appointments';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => 'string',
    ];

    // Validation rules for the model (basic - no restrictions)
    public static $rules = [
        'asset_id' => 'nullable|exists:assets,id',
        'specialist_id' => 'nullable|exists:specialists,id',
        'start_at' => 'required|date',
        'end_at' => 'required|date|after:start_at',
        'notes' => 'nullable|string|max:1000',
    ];

    /**
     * Calculate status based on current time
     * - active: current time is before start_at
     * - in_progress: current time is between start_at and end_at
     * - completed: current time is after end_at
     */
    public function calculateStatus(): string
    {
        $now = now();
        $startAt = $this->start_at;
        $endAt = $this->end_at;

        if ($now->lt($startAt)) {
            return 'active';
        } elseif ($now->gte($startAt) && $now->lte($endAt)) {
            return 'in_progress';
        } else {
            return 'completed';
        }
    }

    /**
     * Boot method to auto-calculate status
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($appointment) {
            // Always auto-calculate status based on current time
            // Status is never set manually, always calculated
            // Recalculate on every save to ensure it's up-to-date
            if ($appointment->start_at && $appointment->end_at) {
                $appointment->status = $appointment->calculateStatus();
            }
        });
    }

    // Relationships
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function specialist()
    {
        return $this->belongsTo(Specialist::class);
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

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByAsset($query, $assetId)
    {
        return $query->where('asset_id', $assetId);
    }

    public function scopeBySpecialist($query, $specialistId)
    {
        return $query->where('specialist_id', $specialistId);
    }

    public function scopeOverlapping($query, $assetId, $startAt, $endAt, $excludeId = null)
    {
        if ($assetId) {
            $query->where('asset_id', $assetId);
        }
        
        // Don't filter by status for overlapping check - check all appointments
        $query->where(function ($q) use ($startAt, $endAt) {
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
