<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class PaymentTerm extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $table = 'payment_terms';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'nb_days' => 'integer',
        'active' => 'boolean',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class, 'payment_term_id');
    }
}
