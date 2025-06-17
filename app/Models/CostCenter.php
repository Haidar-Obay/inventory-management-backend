<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class CostCenter extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'cost_centers';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'active',
        'sub_cost_center_of'
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(CostCenter::class, 'sub_cost_center_of');
    }

    public function children()
    {
        return $this->hasMany(CostCenter::class, 'sub_cost_center_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isSubCostCenter()
    {
        return !is_null($this->sub_cost_center_of);
    }

    public function hasSubCostCenters()
    {
        return $this->children()->exists();
    }
}
