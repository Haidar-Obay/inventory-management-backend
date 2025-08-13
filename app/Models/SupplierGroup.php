<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class SupplierGroup extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'supplier_groups';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the suppliers in this group.
     */
    public function suppliers()
    {
        return $this->hasMany(Supplier::class, 'supplier_group_id');
    }
}
