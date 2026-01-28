<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ItemGroup extends Model implements Auditable
{
    use AuditableTrait, SoftDeletes;

    protected $guarded = ['id'];

    protected $table = 'item_groups';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the items in this group.
     */
    public function items()
    {
        return $this->hasMany(Item::class, 'item_group_id');
    }
}
