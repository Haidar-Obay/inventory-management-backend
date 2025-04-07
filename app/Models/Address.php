<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $guarded = ["id"];
    protected $table = 'addresses';
    protected $primaryKey = 'id';
    public $timestamps = false;
    
    public function country ()
    {
        return $this->belongsTo(Country::class);
    }

    public function city ()
    {
        return $this->belongsTo(City::class);
    }

    public function province ()
    {
        return $this->belongsTo(Province::class);
    }

    public function billingCustomers()
    {
        return $this->hasMany(Customer::class, 'billing_address_id');
    }

    public function shippingCustomers()
    {
        return $this->hasMany(Customer::class, 'shipping_address_id');
    }
}
