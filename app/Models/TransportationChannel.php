<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class TransportationChannel extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'transportation_channels';
    protected $primaryKey = 'id';
    public $timestamps = true;

    public function parent()
    {
        return $this->belongsTo(TransportationChannel::class, 'sub_transportation_of');
    }

    public function children()
    {
        return $this->hasMany(TransportationChannel::class, 'sub_transportation_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isSubTransportationChannel()
    {
        return !is_null($this->sub_transportation_of);
    }

    public function hasSubTransportationChannels()
    {
        return $this->children()->exists();
    }
}
