<?php

namespace App\Models;

use App\Enums\CashAccountType;
use Illuminate\Database\Eloquent\Model;

class CashAccount extends Model
{
    protected $guarded = ['id'];

    protected $table = 'cash_accounts';

    public $timestamps = true;

    protected $casts = [
        'type' => CashAccountType::class,
        'opening_amount' => 'decimal:4',
        'opening_date' => 'date',
    ];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
