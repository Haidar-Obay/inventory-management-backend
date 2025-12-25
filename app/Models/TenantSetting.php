<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Contracts\Auditable;

class TenantSetting extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $table = 'tenant_settings';

    protected $fillable = [
        'company_name',
        'location',
        'main_language',
        'preferred_mode',
        'time_format',
        'primary_currency_id',
        'secondary_currency_id',
        'working_time_from',
        'working_time_to',
        'days_off',
        'setup_completed',
        'completed_at',
        'additional_settings',
    ];

    protected $casts = [
        'days_off' => 'array',
        'setup_completed' => 'boolean',
        'completed_at' => 'datetime',
        'working_time_from' => 'string',
        'working_time_to' => 'string',
        'additional_settings' => 'array',
    ];

    /**
     * Get the primary currency.
     */
    public function primaryCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'primary_currency_id');
    }

    /**
     * Get the secondary currency.
     */
    public function secondaryCurrency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'secondary_currency_id');
    }

    /**
     * Check if setup is completed.
     */
    public function isSetupCompleted(): bool
    {
        return $this->setup_completed === true;
    }

    /**
     * Mark setup as completed.
     */
    public function markAsCompleted(): void
    {
        $this->update([
            'setup_completed' => true,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get or create tenant settings (singleton pattern).
     */
    public static function getSettings(): self
    {
        return static::firstOrCreate([], [
            'main_language' => 'en',
            'preferred_mode' => 'light',
            'time_format' => '24',
            'setup_completed' => false,
        ]);
    }
}
