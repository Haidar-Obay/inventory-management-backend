<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class MediaChannel extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'media_channels';

    protected $primaryKey = 'id';

    public $timestamps = true;

    public function parent()
    {
        return $this->belongsTo(MediaChannel::class, 'sub_media_of');
    }

    public function children()
    {
        return $this->hasMany(MediaChannel::class, 'sub_media_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isSubMediaChannel()
    {
        return ! is_null($this->sub_media_of);
    }

    public function hasSubMediaChannels()
    {
        return $this->children()->exists();
    }

    // Customer relationship (one-to-many)
    public function customers()
    {
        return $this->hasMany(Customer::class, 'media_channel_id');
    }
}
