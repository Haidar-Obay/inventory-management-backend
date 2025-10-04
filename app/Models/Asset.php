<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    public function assignments()
    {
        return $this->hasMany(Assignment::class);
    }

    public function activeAssignments()
    {
        return $this->hasMany(Assignment::class)->where('status', 'active');
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
            ->whereDoesntHave('activeAssignments');
    }
}
