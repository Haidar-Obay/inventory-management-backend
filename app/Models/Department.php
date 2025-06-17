<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Department extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'departments';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(Department::class, 'sub_department_of');
    }

    public function children()
    {
        return $this->hasMany(Department::class, 'sub_department_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isSubDepartment()
    {
        return !is_null($this->sub_department_of);
    }

    public function hasSubDepartments()
    {
        return $this->children()->exists();
    }
}
