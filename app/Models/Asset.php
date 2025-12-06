<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Asset extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'assets';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'type' => 'string',
        'status' => 'string',
    ];

    // Validation rules for the model
    public static $rules = [
        'section_id' => 'required|exists:sections,id',
        'name' => 'required|string|max:255',
        'type' => 'required|in:machine,bed,equipment,furniture,other',
        'status' => 'required|in:active,maintenance,inactive,retired',
    ];

    // Relationships
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function room()
    {
        return $this->hasOneThrough(Room::class, Section::class, 'id', 'id', 'section_id', 'room_id');
    }

    /**
     * Get appointments that use this asset through the appointment_service pivot table
     */
    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_service', 'asset_id', 'appointment_id')
            ->whereNotNull('appointment_service.asset_id');
    }

    /**
     * Get active appointments that use this asset
     */
    public function activeAppointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_service', 'asset_id', 'appointment_id')
            ->whereNotNull('appointment_service.asset_id')
            ->where('appointments.status', 'active');
    }

    /**
     * Get the tasks for this asset.
     */
    public function tasks()
    {
        return $this->morphMany(Task::class, 'schedulable');
    }

    /**
     * Get the events for this asset.
     */
    public function events()
    {
        return $this->morphMany(Event::class, 'schedulable');
    }

    /**
     * Services that can be performed with this asset.
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_assets')->withTimestamps();
    }

    // Scopes
    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
            ->whereDoesntHave('activeAppointments');
    }
}
