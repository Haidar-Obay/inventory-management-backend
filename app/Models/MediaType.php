<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class MediaType extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $fillable = ['name', 'sub_media_type_of'];

    protected $casts = [
        'sub_media_type_of' => 'integer',
    ];

    public function parent()
    {
        return $this->belongsTo(MediaType::class, 'sub_media_type_of');
    }

    public function children()
    {
        return $this->hasMany(MediaType::class, 'sub_media_type_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function isSubMediaType()
    {
        return ! is_null($this->sub_media_type_of);
    }

    public function hasSubMediaTypes()
    {
        return $this->children()->exists();
    }
}
