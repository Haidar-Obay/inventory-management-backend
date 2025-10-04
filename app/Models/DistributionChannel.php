<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class DistributionChannel extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'distribution_channels';

    protected $primaryKey = 'id';

    public $timestamps = true;

    public function parent()
    {
        return $this->belongsTo(DistributionChannel::class, 'sub_distribution_of');
    }

    public function children()
    {
        return $this->hasMany(DistributionChannel::class, 'sub_distribution_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isSubDistributionChannel()
    {
        return ! is_null($this->sub_distribution_of);
    }

    public function hasSubDistributionChannels()
    {
        return $this->children()->exists();
    }
}
