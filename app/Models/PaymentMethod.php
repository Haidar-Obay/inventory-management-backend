<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class PaymentMethod extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'payment_methods';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'is_credit_card' => 'boolean',
        'active' => 'boolean',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }
}
