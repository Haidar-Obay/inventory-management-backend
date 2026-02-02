<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class ExchangeRate extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'currency_id',
        'rate',
        'rate_source',
        'effective_from',
        'effective_to',
        'updated_by',
        'notes',
    ];

    protected $casts = [
        'rate' => 'decimal:4',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    /**
     * Get the currency this rate belongs to.
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Scope to get only current rates (effective_to is NULL).
     */
    public function scopeCurrent($query)
    {
        return $query->whereNull('effective_to');
    }

    /**
     * Scope to get historical rates (effective_to is NOT NULL).
     */
    public function scopeHistorical($query)
    {
        return $query->whereNotNull('effective_to');
    }

    /**
     * Scope to get rates for a specific date.
     */
    public function scopeForDate($query, $date)
    {
        return $query->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $date);
            });
    }
}
