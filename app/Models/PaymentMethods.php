<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    protected $guarded = ['id'];
    protected $table = 'payment_methods';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers()
    {
        return $this->hasMany(Customer::class, 'primary_payment_method_id');
    }
}
