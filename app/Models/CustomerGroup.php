<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class CustomerGroup extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'customer_groups';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the customers in this group.
     */
    public function customers()
    {
        return $this->hasMany(Customer::class, 'customer_group_id');
    }
}
