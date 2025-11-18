<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'price',
        'billing_cycle',
        'is_active',
        'features',
        'max_currencies',
        'max_users',
        'max_customers',
        'scheduler_mode',
        'is_default',
    ];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'price' => 'decimal:2',
        'scheduler_mode' => 'string',
    ];

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }

    public function isDefault(): bool
    {
        return $this->is_default;
    }

    public function isActive(): bool
    {
        return $this->is_active;
    }

    public function getFeature(string $feature)
    {
        return $this->features[$feature] ?? null;
    }

    public function hasFeature(string $feature): bool
    {
        return isset($this->features[$feature]) && $this->features[$feature];
    }

    public function canAddCurrency(int $currentCount = 0): bool
    {
        return $currentCount < $this->max_currencies;
    }

    public function canAddUser(int $currentCount = 0): bool
    {
        return $this->max_users === null || $currentCount < $this->max_users;
    }

    public function canAddCustomer(int $currentCount = 0): bool
    {
        return $this->max_customers === null || $currentCount < $this->max_customers;
    }

    /**
     * Get scheduler mode for this plan
     */
    public function getSchedulerMode(): string
    {
        return $this->scheduler_mode ?? 'basic';
    }

    /**
     * Check if plan has advanced scheduler
     */
    public function hasAdvancedScheduler(): bool
    {
        return $this->getSchedulerMode() === 'advanced';
    }

    /**
     * Check if plan has basic scheduler
     */
    public function hasBasicScheduler(): bool
    {
        return $this->getSchedulerMode() === 'basic';
    }
}
