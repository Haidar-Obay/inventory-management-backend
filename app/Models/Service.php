<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'service_category_id',
        'department_id',
        'sub_department_id',
        'cnss_code',
        'result_after_days',
        'needs_specialist',
        'duration_minutes',
        'normal_price',
        'vip_price',
        'price_in_group',
        'event_pricing',
        'price_calculated_by_hour',
        'hour_price',
        'estimated_cost',
        'image',
        'service_color',
        'service_sex',
        'active',
    ];

    protected $casts = [
        'needs_specialist' => 'boolean',
        'event_pricing' => 'boolean',
        'price_calculated_by_hour' => 'boolean',
        'active' => 'boolean',
    ];

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function subDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'sub_department_id');
    }

    public function specialists(): BelongsToMany
    {
        return $this->belongsToMany(Specialist::class, 'service_specialist')->withTimestamps();
    }

    public function neededItems(): HasMany
    {
        return $this->hasMany(ServiceNeededItem::class);
    }

    public function advancedPricings(): HasMany
    {
        return $this->hasMany(ServiceAdvancedPricing::class);
    }

    public function associationPrices(): HasMany
    {
        return $this->hasMany(AssociationServicePrice::class);
    }

    public function referrerRules(): HasMany
    {
        return $this->hasMany(ReferrerServiceCommission::class);
    }
}
