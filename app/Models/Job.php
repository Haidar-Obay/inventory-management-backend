<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Job extends Model implements Auditable
{
    use HasFactory, SoftDeletes, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'jobs';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'start_date' => 'date',
        'expected_date' => 'date',
        'end_date' => 'date',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function isCompleted()
    {
        return !is_null($this->end_date);
    }

    public function isOverdue()
    {
        return !$this->isCompleted() && $this->expected_date->isPast();
    }

    public function getDuration()
    {
        if (!$this->isCompleted()) {
            return null;
        }
        return $this->start_date->diffInDays($this->end_date);
    }

    public function getExpectedDuration()
    {
        return $this->start_date->diffInDays($this->expected_date);
    }
}
