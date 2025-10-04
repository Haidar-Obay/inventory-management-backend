<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierOpeningBalance extends Model
{
    protected $table = 'supplier_opening_balances';

    protected $fillable = [
        'supplier_id',
        'currency_id',
        'opening_amount',
        'opening_date',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'opening_amount' => 'decimal:2',
        'opening_date' => 'date',
        'is_active' => 'boolean',
    ];

    /**
     * Get the supplier that owns the opening balance
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the currency for this opening balance
     */
    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    /**
     * Scope to get only active opening balances
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Scope to get opening balances for a specific currency
     */
    public function scopeForCurrency(Builder $query, int $currencyId): void
    {
        $query->where('currency_id', $currencyId);
    }

    /**
     * Scope to get opening balances for a specific supplier
     */
    public function scopeForSupplier(Builder $query, int $supplierId): void
    {
        $query->where('supplier_id', $supplierId);
    }
}
