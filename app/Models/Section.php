<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Section extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'sections';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'order_index' => 'integer',
    ];

    // Validation rules for the model
    public static $rules = [
        'room_id' => 'required|exists:rooms,id',
        'name' => 'required|string|max:255',
        'order_index' => 'nullable|integer|min:0',
    ];

    // Relationships
    public function room()
    {
        return $this->belongsTo(Room::class);
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Get the tasks for this section.
     */
    public function tasks()
    {
        return $this->morphMany(Task::class, 'schedulable');
    }

    /**
     * Get the events for this section.
     */
    public function events()
    {
        return $this->morphMany(Event::class, 'schedulable');
    }

    // Scopes
    public function scopeOrdered($query)
    {
        return $query->orderBy('order_index')->orderBy('name');
    }

    public function scopeByRoom($query, $roomId)
    {
        return $query->where('room_id', $roomId);
    }
}
