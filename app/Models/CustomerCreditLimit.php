<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class CustomerCreditLimit extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];
    protected $table = 'customer_credit_limits';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'credit_limit' => 'decimal:2',
        'used_credit' => 'decimal:2',
        'available_credit' => 'decimal:2',
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
    public function updateAvailableCredit()
    {
        $this->available_credit = $this->credit_limit - $this->used_credit;
        $this->save();
    }

    public function hasAvailableCredit($amount = 0)
    {
        return $this->available_credit >= $amount;
    }

    public function getUtilizationPercentage()
    {
        if ($this->credit_limit == 0) {
            return 0;
        }
        return ($this->used_credit / $this->credit_limit) * 100;
    }

    // Boot method to automatically update available_credit
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->available_credit = $model->credit_limit - $model->used_credit;
        });
    }
}
