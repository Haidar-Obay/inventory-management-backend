<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    protected $guarded = ['id'];
    protected $table = 'countries';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function addresses()
    {
        return $this->hasMany(Address::class, 'country_id');
    }
}
