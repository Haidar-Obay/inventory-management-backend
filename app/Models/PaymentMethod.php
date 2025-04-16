<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
class PaymentMethod extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;
    protected $guarded = ['id'];
    protected $table = 'payment_methods';
    protected $primaryKey = 'id';
    public $timestamps = false;

    public function customers()
    {
        return $this->hasMany(Customer::class, 'primary_payment_method_id');
    }
}
