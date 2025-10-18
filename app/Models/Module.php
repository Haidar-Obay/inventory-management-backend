<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Module extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'active',
        'icon',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function pages(): HasMany
    {
        return $this->hasMany(ModulePage::class);
    }

    public function resources(): HasMany
    {
        return $this->hasMany(ModuleResource::class);
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'tenant_modules')
            ->withPivot('assigned_price', 'is_included', 'subscription_plan_id')
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
