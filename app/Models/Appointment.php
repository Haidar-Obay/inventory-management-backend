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
        'cancellation_reason',
        'cancelled_date',
        'cancelled_time',
        'color',
        'status',
    ];

    protected $table = 'appointments';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'cancelled_date' => 'date',
        'cancelled_time' => 'datetime:H:i:s',
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
     * - active: current time is before start_at (only status that can be auto-calculated)
     * - in_progress: set manually via visits
     * - completed: set manually via visits
     * - cancelled: set manually
     * Note: Only 'active' status is auto-calculated. Other statuses are set manually via visits.
     */
    public function calculateStatus(): string
    {
        // If status is already set to in_progress, completed, or cancelled, preserve it
        // These statuses are managed by visits, not auto-calculated
        if (in_array($this->status, ['in_progress', 'completed', 'cancelled'])) {
            return $this->status;
        }

        $now = now();
        $startAt = $this->start_at;

        // Only auto-calculate 'active' status if we're before start_at
        if ($startAt && $now->lt($startAt)) {
            return 'active';
        }

        // If we're past start_at but status is still 'active' or null, keep as is
        // Visits will handle changing to in_progress/completed
        return $this->status ?? 'active';
    }

    /**
     * Boot method to auto-calculate status
     * Only auto-calculates 'active' status. Other statuses (in_progress, completed, cancelled) are set manually via visits.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($appointment) {
            // Only auto-calculate 'active' status if:
            // 1. We have start_at
            // 2. Status is not already set to in_progress, completed, or cancelled (these are managed by visits)
            // 3. Status wasn't manually set in this save operation
            if ($appointment->start_at) {
                $manualStatuses = ['in_progress', 'completed', 'cancelled'];
                $isManualStatus = in_array($appointment->status, $manualStatuses);
                $statusWasManuallySet = $appointment->isDirty('status') && $appointment->getOriginal('status') !== null;
                
                // Only auto-calculate if status is not manually set and not a visit-managed status
                if (!$isManualStatus && !$statusWasManuallySet) {
                    $calculatedStatus = $appointment->calculateStatus();
                    // Only set to 'active' if calculated status is 'active'
                    // Don't change if it's already in_progress, completed, or cancelled
                    if ($calculatedStatus === 'active') {
                        $appointment->status = 'active';
                    }
                }
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

    public function scopeCancelled($query)
    {
        return $query->where('status', 'cancelled');
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
