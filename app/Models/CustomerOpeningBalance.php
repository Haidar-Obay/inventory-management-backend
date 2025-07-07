<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class CustomerOpeningBalance extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];
    protected $table = 'customer_opening_balances';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'opening_date' => 'date',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForCurrency($query, $currencyId)
    {
        return $query->where('currency_id', $currencyId);
    }

    // Helper methods
    public function getFormattedAmount()
    {
        return number_format($this->opening_amount, 2);
    }

    public function isPositive()
    {
        return $this->opening_amount > 0;
    }

    public function isNegative()
    {
        return $this->opening_amount < 0;
    }

    public function isZero()
    {
        return $this->opening_amount == 0;
    }

    public function getBalanceType()
    {
        if ($this->opening_amount > 0) {
            return 'credit';
        } elseif ($this->opening_amount < 0) {
            return 'debit';
        } else {
            return 'zero';
        }
    }
}
