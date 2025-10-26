<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class SalesChannel extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'sales_channels';

    protected $primaryKey = 'id';

    public $timestamps = true;

    public function parent()
    {
        return $this->belongsTo(SalesChannel::class, 'sub_sales_of');
    }

    public function children()
    {
        return $this->hasMany(SalesChannel::class, 'sub_sales_of');
    }

    public function getAllChildren()
    {
        return $this->children()->with('children');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isSubSalesChannel()
    {
        return ! is_null($this->sub_sales_of);
    }

    public function hasSubSalesChannels()
    {
        return $this->children()->exists();
    }

    // Customer relationship (one-to-many)
    public function customers()
    {
        return $this->hasMany(Customer::class, 'sales_channel_id');
    }
}
