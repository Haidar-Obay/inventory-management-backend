<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Trade extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'trades';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    // Customer relationship (one-to-many)
    public function customers()
    {
        return $this->hasMany(Customer::class, 'trade_id');
    }
}
