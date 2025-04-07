<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    protected $guarded = ['id'];
    protected $table = 'currencies';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers()
    {
        return $this->hasMany(Customer::class, 'opening_currency_id');
    }
}
