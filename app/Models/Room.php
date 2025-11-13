<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Room extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'rooms';

    protected $primaryKey = 'id';

    public $timestamps = true;

    // Validation rules for the model
    public static $rules = [
        'name' => 'required|string|max:255|unique:rooms,name',
        'location' => 'required|string|max:255',
    ];

    // Relationships
    public function sections()
    {
        return $this->hasMany(Section::class);
    }

    public function assets()
    {
        return $this->hasManyThrough(Asset::class, Section::class);
    }

    public function appointments()
    {
        return $this->hasManyThrough(Appointment::class, Asset::class, 'section_id', 'asset_id', 'id', 'section_id')
            ->join('sections', 'sections.id', '=', 'assets.section_id')
            ->where('sections.room_id', '=', 'rooms.id');
    }

    /**
     * Get the tasks for this room.
     */
    public function tasks()
    {
        return $this->morphMany(Task::class, 'schedulable');
    }

    /**
     * Get the events for this room.
     */
    public function events()
    {
        return $this->morphMany(Event::class, 'schedulable');
    }
}
