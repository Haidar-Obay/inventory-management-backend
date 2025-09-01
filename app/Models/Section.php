<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Section extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

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
