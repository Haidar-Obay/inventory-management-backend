<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class Province extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = ['id'];
    protected $table = 'provinces';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function addresses()
    {
        return $this->hasMany(Address::class, 'province_id');
    }
}
