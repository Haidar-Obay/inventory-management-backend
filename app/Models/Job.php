<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Job extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'projects_jobs';

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
        return ! is_null($this->end_date);
    }

    public function isOverdue()
    {
        return ! $this->isCompleted() && $this->expected_date->isPast();
    }

    public function getDuration()
    {
        if (! $this->isCompleted()) {
            return;
        }

        return $this->start_date->diffInDays($this->end_date);
    }

    public function getExpectedDuration()
    {
        return $this->start_date->diffInDays($this->expected_date);
    }
}
