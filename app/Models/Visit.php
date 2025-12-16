<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Visit extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'customer_id',
        'appointment_id',
        'status',
        'arrived_at',
        'in_progress_at',
        'completed_at',
        'cancelled_at',
        'notes',
        'cancellation_reason',
    ];

    protected $table = 'visits';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'arrived_at' => 'datetime',
        'in_progress_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'status' => 'string',
    ];

    /**
     * Basic validation rules for visits.
     */
    public static $rules = [
        'customer_id' => 'required|exists:customers,id',
        'appointment_id' => 'nullable|exists:appointments,id',
        'status' => 'required|in:arrived,in_progress,completed,cancelled',
        'notes' => 'nullable|string|max:1000',
        'cancellation_reason' => 'nullable|string|max:1000',
    ];

    /**
     * Relationships
     */
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function appointment()
    {
        return $this->belongsTo(Appointment::class);
    }

    /**
     * Automatically maintain stage timestamps when status changes.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function (Visit $visit) {
            if ($visit->isDirty('status')) {
                $now = now();

                switch ($visit->status) {
                    case 'arrived':
                        $visit->arrived_at = $visit->arrived_at ?? $now;

                        break;
                    case 'in_progress':
                        $visit->in_progress_at = $visit->in_progress_at ?? $now;

                        break;
                    case 'completed':
                        $visit->completed_at = $visit->completed_at ?? $now;

                        break;
                    case 'cancelled':
                        $visit->cancelled_at = $visit->cancelled_at ?? $now;

                        break;
                }
            }
        });
    }

    /**
     * Apply this visit's status to its linked appointment (if any).
     *
     * Visits are the single source of truth for in_progress, completed, cancelled.
     */
    public function applyStatusToAppointment(): void
    {
        if (! $this->appointment) {
            return;
        }

        $appointment = $this->appointment;

        switch ($this->status) {
            case 'in_progress':
                // Only update if not already cancelled
                if ($appointment->status !== 'cancelled') {
                    $appointment->status = 'in_progress';
                    $appointment->saveQuietly();
                }

                break;

            case 'completed':
                // Only update if not already cancelled
                if ($appointment->status !== 'cancelled') {
                    $appointment->status = 'completed';
                    $appointment->saveQuietly();
                }

                break;

            case 'cancelled':
                $now = now();
                $appointment->status = 'cancelled';
                // Prefer visit cancellation_reason if present
                $appointment->cancellation_reason = $this->cancellation_reason ?: $appointment->cancellation_reason;
                $appointment->cancelled_date = $now->toDateString();
                $appointment->cancelled_time = $now->format('H:i:s');
                $appointment->saveQuietly();

                break;

            case 'arrived':
                // If changing from cancelled to arrived, undo the appointment cancellation
                if ($appointment->status === 'cancelled') {
                    $appointment->status = 'active';
                    $appointment->cancellation_reason = null;
                    $appointment->cancelled_date = null;
                    $appointment->cancelled_time = null;
                    $appointment->saveQuietly();
                }

                // Do not auto-change appointment back from in_progress/completed here.
                // Active status remains driven by time, and other transitions are explicit.
                break;
            default:
                break;
        }
    }
}
