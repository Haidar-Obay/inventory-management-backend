<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class ReferBy extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = ["id"];
    protected $table = 'refer_bies';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers ()
    {
        return $this->hasMany(Customer::class,'refer_by_id');
    }
}
