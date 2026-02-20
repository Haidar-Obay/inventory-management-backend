<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use OwenIt\Auditing\Contracts\Auditable;

class Currency extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'currencies';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'rate' => 'decimal:4',
        'auto_update_enabled' => 'boolean',
        'active' => 'boolean',
        'rate_updated_at' => 'datetime',
        'smallest_unit' => 'decimal:6',
        'round_limit' => 'decimal:6',
        'acceptable_amount_overdue' => 'decimal:4',
        'allowed_difference_in_receipt' => 'decimal:4',
        'allowed_difference_in_payment' => 'decimal:4',
    ];

    /**
     * Get all exchange rate history for this currency.
     */
    public function exchangeRates(): HasMany
    {
        return $this->hasMany(ExchangeRate::class);
    }

    /**
     * Get the current exchange rate (most recent with effective_to = NULL).
     */
    public function currentExchangeRate()
    {
        return $this->exchangeRates()
            ->whereNull('effective_to')
            ->orderBy('effective_from', 'desc')
            ->first();
    }

    /**
     * Check if this currency is the primary currency.
     */
    public function isPrimary(): bool
    {
        $settings = TenantSetting::getSettings();

        return $settings->primary_currency_id === $this->id;
    }

    /**
     * Get the primary currency for this tenant.
     */
    public static function getPrimary(): ?self
    {
        $settings = TenantSetting::getSettings();
        if (! $settings->primary_currency_id) {
            return null;
        }

        return static::find($settings->primary_currency_id);
    }

    /**
     * Update the exchange rate and create history record.
     * Only creates history if rate or source has changed.
     */
    public function updateRate(float $rate, string $source = 'manual', ?string $updatedBy = null, ?string $notes = null): void
    {
        // If this is the primary currency, rate must be 1.0
        if ($this->isPrimary() && $rate != 1.0000) {
            throw new \InvalidArgumentException('Primary currency rate must always be 1.0000');
        }

        // Check if rate or source has changed
        $rateChanged = abs((float) $this->rate - $rate) > 0.0001; // Allow small floating point differences
        $sourceChanged = $this->rate_source !== $source;
        $hasChanged = $rateChanged || $sourceChanged;

        // Only update if something changed
        if (! $hasChanged) {
            // Nothing changed, don't update or create history
            return;
        }

        // Archive current rate if it exists
        $currentRate = $this->currentExchangeRate();
        if ($currentRate) {
            $currentRate->update(['effective_to' => now()]);
        }

        // Create new rate history record
        ExchangeRate::create([
            'currency_id' => $this->id,
            'rate' => $rate,
            'rate_source' => $source,
            'effective_from' => now(),
            'effective_to' => null,
            'updated_by' => $updatedBy ?? (Auth::check() ? Auth::user()->name : 'System'),
            'notes' => $notes,
        ]);

        // Update currency record
        $this->update([
            'rate' => $rate,
            'rate_source' => $source,
            'rate_updated_at' => now(),
            'rate_updated_by' => $updatedBy ?? (Auth::check() ? Auth::user()->name : 'System'),
        ]);

        // Clear cache
        $tenantId = tenant('id');
        app('cache')->store('database')->forget("tenant_{$tenantId}_currencies");
        app('cache')->store('database')->forget("tenant_{$tenantId}_currency_show_{$this->id}");
    }

    public function customers()
    {
        return $this->hasMany(Customer::class, 'opening_currency_id');
    }
}
