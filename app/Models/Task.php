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
        'date',
        'time',
        'is_all_day',
        'repeat',
        'due_at',
        'status',
        'priority',
        'color',
    ];

    // Status is now manually set by user (completed/uncompleted)
    // No auto-calculation needed

    protected $table = 'tasks';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'date' => 'date',
        'time' => 'string', // Time stored as string (HH:mm format)
        'is_all_day' => 'boolean',
        'repeat' => 'array', // JSON to array
        'due_at' => 'date',
        'status' => 'string',
        'priority' => 'string',
    ];

    // Validation rules for the model
    public static $rules = [
        'schedulable_id' => 'required|integer',
        'schedulable_type' => 'required|string|in:App\Models\User,App\Models\Specialist,App\Models\Asset,App\Models\Room,App\Models\Section',
        'title' => 'required|string|max:255',
        'description' => 'nullable|string|max:2000',
        'date' => 'required|date',
        'time' => 'nullable|date_format:H:i',
        'is_all_day' => 'boolean',
        'repeat' => 'nullable|array',
        'due_at' => 'nullable|date',
        'status' => 'required|in:completed,uncompleted',
        'priority' => 'required|in:low,medium,high,urgent',
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
        return $query->where('status', 'uncompleted');
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
            ->where('status', 'uncompleted');
    }

    public function scopeForSchedulable($query, $schedulableType, $schedulableId)
    {
        return $query->where('schedulable_type', $schedulableType)
            ->where('schedulable_id', $schedulableId);
    }
}
