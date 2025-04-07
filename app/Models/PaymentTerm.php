<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PaymentTerm extends Model
{
    protected $guarded = ['id'];
    protected $table = 'payment_terms';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers()
    {
        return $this->hasMany(Customer::class, 'payment_term_id');
    }
}
