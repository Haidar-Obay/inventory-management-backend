<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Task extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = [
        'schedulable_id',
        'schedulable_type',
        'title',
        'description',
        'start_at',
        'end_at',
        'due_at',
        'status',
        'priority',
        'notes',
        'color',
    ];

    protected $table = 'tasks';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'due_at' => 'datetime',
        'status' => 'string',
        'priority' => 'string',
    ];

    // Validation rules for the model
    public static $rules = [
        'schedulable_id' => 'required|integer',
        'schedulable_type' => 'required|string|in:App\Models\User,App\Models\Specialist,App\Models\Asset,App\Models\Room,App\Models\Section',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:2000',
        'start_at' => 'required|date',
        'end_at' => 'nullable|date|after:start_at',
        'due_at' => 'nullable|date',
        'status' => 'required|in:pending,in_progress,completed,cancelled',
        'priority' => 'required|in:low,medium,high,urgent',
        'notes' => 'nullable|string|max:1000',
        'color' => 'nullable|string|regex:/^#[0-9A-Fa-f]{6}$/',
    ];

    // Polymorphic relationship
    public function schedulable()
    {
        return $this->morphTo();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'in_progress']);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeOverdue($query)
    {
        return $query->where('due_at', '<', now())
            ->whereIn('status', ['pending', 'in_progress']);
    }

    public function scopeForSchedulable($query, $schedulableType, $schedulableId)
    {
        return $query->where('schedulable_type', $schedulableType)
            ->where('schedulable_id', $schedulableId);
    }
}

