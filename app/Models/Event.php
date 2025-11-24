<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Event extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'schedulable_id',
        'schedulable_type',
        'title',
        'description',
        'start_at',
        'end_at',
        'location',
        'notes',
        'color',
        'is_all_day',
        // Note: 'status' is NOT in fillable - it's auto-calculated
    ];

    protected $table = 'events';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'status' => 'string',
        'is_all_day' => 'boolean',
    ];

    // Validation rules for the model
    public static $rules = [
        'schedulable_id' => 'required|integer',
        'schedulable_type' => 'required|string|in:App\Models\User,App\Models\Specialist,App\Models\Asset,App\Models\Room,App\Models\Section',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:2000',
        'start_at' => 'required|date',
        'end_at' => 'nullable|date|after:start_at',
        'status' => 'nullable|in:scheduled,ongoing,completed,cancelled', // Optional - will be auto-calculated if not provided
        'location' => 'nullable|string|max:255',
        'notes' => 'nullable|string|max:1000',
        'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
        'is_all_day' => 'boolean',
    ];

    /**
     * Calculate status based on current time
     * - scheduled: current time is before start_at
     * - ongoing: current time is between start_at and end_at
     * - completed: current time is after end_at
     * Note: 'cancelled' status can only be set manually via status toggle
     */
    public function calculateStatus(): string
    {
        // If status is manually set to 'cancelled', don't override it
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        $now = now();
        $startAt = $this->start_at;
        $endAt = $this->end_at;

        if ($now->lt($startAt)) {
            return 'scheduled';
        } elseif ($endAt && $now->gte($startAt) && $now->lte($endAt)) {
            return 'ongoing';
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

        static::saving(function ($event) {
            // Auto-calculate status based on current time
            // Only skip auto-calculation if status is explicitly set to 'cancelled'
            // This allows the status toggle to work (setting to cancelled)
            // but auto-calculates for all other cases
            if ($event->start_at) {
                // If status is not 'cancelled', calculate it based on time
                // This handles both new events and updates (unless status is 'cancelled')
                if ($event->status !== 'cancelled') {
                    $event->status = $event->calculateStatus();
                }
            }
        });
    }

    // Polymorphic relationship
    public function schedulable()
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['scheduled', 'ongoing']);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeAllDay($query)
    {
        return $query->where('is_all_day', true);
    }

    public function scopeForSchedulable($query, $schedulableType, $schedulableId)
    {
        return $query->where('schedulable_type', $schedulableType)
            ->where('schedulable_id', $schedulableId);
    }

    public function scopeOverlapping($query, $startAt, $endAt, $excludeId = null)
    {
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
