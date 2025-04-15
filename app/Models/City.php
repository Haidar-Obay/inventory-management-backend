<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class City extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];
    protected $table = 'cities';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function addresses()
    {
        return $this->hasMany(Address::class, 'city_id');
    }
}
