<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Project extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'projects';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'expected_date' => 'date',
    ];

    // Relationship with Customer
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function jobs()
    {
        return $this->hasMany(Job::class);
    }

    // Helper method to check if project is completed
    public function isCompleted()
    {
        return ! is_null($this->end_date);
    }

    // Helper method to check if project is overdue
    public function isOverdue()
    {
        return ! $this->isCompleted() && $this->expected_date->isPast();
    }

    // Helper method to get project duration in days
    public function getDuration()
    {
        if (! $this->isCompleted()) {
            return;
        }

        return $this->start_date->diffInDays($this->end_date);
    }
}
