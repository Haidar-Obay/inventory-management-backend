<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class CustomerOpeningBalance extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = ['id', 'customer_id', 'currency_id', 'opening_amount', 'opening_date', 'notes', 'payment_term_id', 'payment_method_id', 'allow_credit', 'payment_day', 'track_payment', 'settlement_method', 'accept_cheques', 'is_active'];

    protected $table = 'customer_opening_balances';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'opening_date' => 'date',
        'allow_credit' => 'boolean',
        'accept_cheques' => 'boolean',
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

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    public function paymentMethod()
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id');
    }

    /**
     * Next available id (same logic as Controller::computeNextAvailableId).
     */
    public static function getNextAvailableId(): int
    {
        $maxId = (new static)->newQuery()->max('id');

        return $maxId !== null ? ((int) $maxId) + 1 : 1;
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
