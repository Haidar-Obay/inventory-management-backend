<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferBy extends Model
{
    protected $guarded = ["id"];
    protected $table = 'refer_bies';
    protected $primaryKey = 'id';
    public $timestamps = false;
    
    public function customers ()
    {
        return $this->hasMany(Customer::class,'refer_by_id');
    }
}
