<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class District extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];
    protected $table = 'districts';
    protected $primaryKey = 'id';
    public $timestamps = true;

    public function addresses()
    {
        return $this->hasMany(Address::class, 'district_id');
    }
}
