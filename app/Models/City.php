<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    protected $guarded = ['id'];
    protected $table = 'cities';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function addresses()
    {
        return $this->hasMany(Address::class, 'city_id');
    }
}
