<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Currency extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'currencies';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
        'smallest_unit' => 'decimal:6',
        'round_limit' => 'decimal:6',
        'acceptable_amount_overdue' => 'decimal:4',
        'allowed_difference_in_receipt' => 'decimal:4',
        'allowed_difference_in_payment' => 'decimal:4',
    ];

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

    public function customers()
    {
        return $this->hasMany(Customer::class, 'opening_currency_id');
    }
}
