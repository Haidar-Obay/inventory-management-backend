<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = ['id'];

    public function customerGroup()
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function salesman()
    {
        return $this->belongsTo(Salesman::class, 'salesman_id');
    }

    public function referBy()
    {
        return $this->belongsTo(ReferBy::class, 'refer_by_id');
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function primaryPaymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'primary_payment_method_id');
    }

    public function openingCurrency()
    {
        return $this->belongsTo(Currency::class, 'opening_currency_id');
    }

    public function Address()
    {
        return $this->hasMany(Address::class, 'id', 'billing_address_id');
    }

    public function parentCustomer()
    {
        return $this->belongsTo(Customer::class, 'parent_customer_id');
    }

    public function subCustomers()
    {
        return $this->hasMany(Customer::class, 'parent_customer_id');
    }
}
