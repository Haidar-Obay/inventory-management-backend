<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Specialist extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'capacity_per_hour',
        'capacity_per_day',
        'phone_1',
        'phone_2',
        'address',
        'email',
    ];

    protected $casts = [
        'capacity_per_hour' => 'integer',
        'capacity_per_day' => 'integer',
    ];

    /**
     * The specialities that belong to the specialist.
     */
    public function specialities(): BelongsToMany
    {
        return $this->belongsToMany(Speciality::class, 'specialist_speciality')
            ->withTimestamps();
    }

    /**
     * The assets (machines, etc.) this specialist can work on.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_specialist')
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_specialist')->withTimestamps();
    }

    /**
     * Get appointments that use this specialist through the appointment_service pivot table
     */
    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_service', 'specialist_id', 'appointment_id')
            ->whereNotNull('appointment_service.specialist_id');
    }

    /**
     * Get the tasks for this specialist.
     */
    public function tasks()
    {
        return $this->morphMany(Task::class, 'schedulable');
    }

    /**
     * Get the events for this specialist.
     */
    public function events()
    {
        return $this->morphMany(Event::class, 'schedulable');
    }
}
