<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Address extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'addresses';

    protected $primaryKey = 'id';

    public $timestamps = false;

    // New relationship using pivot table
    public function customers()
    {
        return $this->belongsToMany(Customer::class, 'customer_addresses')
            ->withPivot(['address_type', 'is_primary', 'address_name', 'notes'])
            ->withTimestamps();
    }

    public function billingCustomers()
    {
        return $this->belongsToMany(Customer::class, 'customer_addresses')
            ->wherePivot('address_type', 'billing')
            ->withPivot(['is_primary', 'address_name', 'notes'])
            ->withTimestamps();
    }

    public function shippingCustomers()
    {
        return $this->belongsToMany(Customer::class, 'customer_addresses')
            ->wherePivot('address_type', 'shipping')
            ->withPivot(['is_primary', 'address_name', 'notes'])
            ->withTimestamps();
    }

    // Location relationships
    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function district()
    {
        return $this->belongsTo(District::class);
    }

    public function zone()
    {
        return $this->belongsTo(Zone::class);
    }
}
