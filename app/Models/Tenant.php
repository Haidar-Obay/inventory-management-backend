<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use OwenIt\Auditing\Contracts\Auditable;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements Auditable, TenantWithDatabase
{
    use HasDatabase, HasDomains;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'id',
        'name',
        'email',
        'subscription_plan_id',
        'subscription_start_date',
        'subscription_end_date',
        'subscription_status',
        'auto_renew',
        'last_billing_date',
        'next_billing_date',
    ];

    protected $casts = [
        'subscription_start_date' => 'date',
        'subscription_end_date' => 'date',
        'last_billing_date' => 'datetime',
        'next_billing_date' => 'datetime',
        'auto_renew' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function subscriptionPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class);
    }

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'tenant_modules')
            ->withPivot('assigned_price', 'is_included', 'subscription_plan_id')
            ->withTimestamps();
    }

    public function hasActiveSubscription(): bool
    {
        return $this->subscription_status === 'active' || $this->subscription_status === 'trial';
    }

    public function isSubscriptionExpired(): bool
    {
        if ($this->subscription_status === 'expired') {
            return true;
        }

        if ($this->subscription_end_date && $this->subscription_end_date->isPast()) {
            return true;
        }

        return false;
    }

    public function canAddCurrency(int $currentCount = 0): bool
    {
        if (! $this->subscriptionPlan) {
            return false;
        }

        return $this->subscriptionPlan->canAddCurrency($currentCount);
    }

    public function canAddUser(int $currentCount = 0): bool
    {
        if (! $this->subscriptionPlan) {
            return false;
        }

        return $this->subscriptionPlan->canAddUser($currentCount);
    }

    public function canAddCustomer(int $currentCount = 0): bool
    {
        if (! $this->subscriptionPlan) {
            return false;
        }

        return $this->subscriptionPlan->canAddCustomer($currentCount);
    }

    public function getSubscriptionFeatures(): array
    {
        return $this->subscriptionPlan?->features ?? [];
    }

    public function hasFeature(string $feature): bool
    {
        return $this->subscriptionPlan?->hasFeature($feature) ?? false;
    }

    public function calculateAssignedTotalPrice(): float
    {
        $base = (float) ($this->subscriptionPlan?->price ?? 0);
        $modulesTotal = (float) $this->modules()->sum('tenant_modules.assigned_price');
        return $base + $modulesTotal;
    }
}
