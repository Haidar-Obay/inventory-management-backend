<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class SupplierGroup extends Model implements Auditable
{
    use AuditableTrait, SoftDeletes;

    protected $guarded = ['id'];
    protected $table = 'supplier_groups';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'is_inactive' => 'boolean',
    ];

    /**
     * Get the suppliers in this group.
     */
    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'supplier_group_id');
    }
}
