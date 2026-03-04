<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CurrencyPairRateHistory extends Model
{
    protected $table = 'currency_pair_rate_history';

    protected $fillable = [
        'from_currency_id',
        'to_currency_id',
        'rate',
        'effective_from',
        'effective_to',
        'updated_by',
    ];

    protected $casts = [
        'rate' => 'decimal:6',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function fromCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'from_currency_id');
    }

    public function toCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'to_currency_id');
    }
}
