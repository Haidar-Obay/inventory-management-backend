<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class Country extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = ['id'];
    protected $table = 'countries';
    protected $primaryKey = 'id';
    public $timestamps = true;

    public function addresses()
    {
        return $this->hasMany(Address::class, 'country_id');
    }

    public function provinces()
    {
        return $this->hasMany(Province::class);
    }

    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function districts()
    {
        return $this->hasMany(District::class);
    }
}
