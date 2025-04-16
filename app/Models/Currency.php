<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class Currency extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = ['id'];
    protected $table = 'currencies';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers()
    {
        return $this->hasMany(Customer::class, 'opening_currency_id');
    }
}
