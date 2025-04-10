<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Salesman extends Model
{
    protected $guarded = ["id"];
    protected $table = 'salesmen';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers ()
    {
        return $this->hasMany(Customer::class,'salesman_id');
    }
}
