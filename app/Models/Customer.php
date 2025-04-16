<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    protected $table = 'customers';
    protected $primaryKey = 'id';
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'attachment_ids' => 'array', // auto-casts to array on get/set
    ];

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

    public function billingAddress()
    {
        return $this->belongsTo(Address::class, 'billing_address_id');
    }

    public function shippingAddress()
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }

    public function attachments()
    {
        return $this->hasMany(CustomerAttachment::class);
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
