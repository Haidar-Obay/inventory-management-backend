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

    /**
     * Legacy relationship - returns first asset from services relationship for backward compatibility
     * Note: This uses a join approach that works with eager loading
     */
    public function asset()
    {
        // Use a join-based approach that Laravel can handle during eager loading
        // The join creates a proper relationship that can reference the parent table
        return $this->hasOne(Asset::class, 'id', 'asset_id')
            ->join('appointment_service', function ($join) {
                $join->on('appointment_service.asset_id', '=', 'assets.id')
                    ->whereColumn('appointment_service.appointment_id', 'appointments.id')
                    ->whereNotNull('appointment_service.asset_id');
            })
            ->orderBy('appointment_service.id')
            ->select('assets.*')
            ->limit(1);
    }

    /**
     * Legacy relationship - returns first specialist from services relationship for backward compatibility
     * Note: This uses a join approach that works with eager loading
     */
    public function specialist()
    {
        // Use a join-based approach that Laravel can handle during eager loading
        return $this->hasOne(Specialist::class, 'id', 'specialist_id')
            ->join('appointment_service', function ($join) {
                $join->on('appointment_service.specialist_id', '=', 'specialists.id')
                    ->whereColumn('appointment_service.appointment_id', 'appointments.id')
                    ->whereNotNull('appointment_service.specialist_id');
            })
            ->orderBy('appointment_service.id')
            ->select('specialists.*')
            ->limit(1);
    }

    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'appointment_customer')
            ->withTimestamps();
    }

    /**
     * Legacy relationship - returns first service from services relationship for backward compatibility
     * Note: This uses a join approach that works with eager loading
     */
    public function service()
    {
        // Use a join-based approach that Laravel can handle during eager loading
        return $this->hasOne(Service::class, 'id', 'service_id')
            ->join('appointment_service', function ($join) {
                $join->on('appointment_service.service_id', '=', 'services.id')
                    ->whereColumn('appointment_service.appointment_id', 'appointments.id');
            })
            ->orderBy('appointment_service.id')
            ->select('services.*')
            ->limit(1);
    }

    /**
     * Many-to-many relationship with services
     * Includes specialist_id and asset_id in pivot for per-service assignment
     */
    public function services()
    {
        return $this->belongsToMany(Service::class, 'appointment_service')
            ->withPivot('specialist_id', 'asset_id')
            ->withTimestamps();
    }

    /**
     * Legacy relationship - returns first section from first service's asset
     */
    public function section()
    {
        return $this->hasOneThrough(Section::class, Asset::class, 'id', 'id', 'asset_id', 'section_id')
            ->whereIn('assets.id', function ($query) {
                $query->select('asset_id')
                    ->from('appointment_service')
                    ->whereColumn('appointment_service.appointment_id', 'appointments.id')
                    ->whereNotNull('asset_id')
                    ->orderBy('appointment_service.id')
                    ->limit(1);
            });
    }

    /**
     * Legacy relationship - returns first room from first service's asset
     */
    public function room()
    {
        return $this->hasOneThrough(Room::class, Asset::class, 'id', 'id', 'asset_id', 'section_id')
            ->join('sections', 'sections.room_id', '=', 'rooms.id')
            ->where('sections.id', '=', 'assets.section_id')
            ->whereIn('assets.id', function ($query) {
                $query->select('asset_id')
                    ->from('appointment_service')
                    ->whereColumn('appointment_service.appointment_id', 'appointments.id')
                    ->whereNotNull('asset_id')
                    ->orderBy('appointment_service.id')
                    ->limit(1);
            });
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
        return $query->whereHas('services', function ($q) use ($assetId) {
            $q->where('appointment_service.asset_id', $assetId);
        });
    }

    public function scopeBySpecialist($query, $specialistId)
    {
        return $query->whereHas('services', function ($q) use ($specialistId) {
            $q->where('appointment_service.specialist_id', $specialistId);
        });
    }

    public function scopeOverlapping($query, $assetId, $startAt, $endAt, $excludeId = null)
    {
        if ($assetId) {
            $query->whereHas('services', function ($q) use ($assetId) {
                $q->where('appointment_service.asset_id', $assetId);
            });
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
