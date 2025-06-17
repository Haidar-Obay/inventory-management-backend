<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class PaymentTerm extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'payment_terms';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'nb_days' => 'integer',
        'is_inactive' => 'boolean',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class, 'payment_term_id');
    }
}
