<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class Salesman extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = ["id"];
    protected $table = 'salesmen';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers ()
    {
        return $this->hasMany(Customer::class,'salesman_id');
    }
}
