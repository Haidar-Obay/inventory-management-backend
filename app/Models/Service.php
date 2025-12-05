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
        'item_id',
        'result_after_days',
        'needs_specialist',
        'needs_asset',
        'duration_minutes',
        'normal_price',
        'vip_price',
        'price_in_group',
        'price_calculated_by_hour',
        'hour_price',
        'cost_price',
        'birthday_price',
        'wedding_price',
        'image',
        'service_color',
        'service_sex',
        'active',
    ];

    protected $casts = [
        'needs_specialist' => 'boolean',
        'needs_asset' => 'boolean',
        'price_calculated_by_hour' => 'boolean',
        'active' => 'boolean',
    ];

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'service_category_id');
    }

    public function specialists(): BelongsToMany
    {
        return $this->belongsToMany(Specialist::class, 'service_specialist')->withTimestamps();
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'service_assets')->withTimestamps();
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

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    /**
     * Get appointments that use this service through the appointment_service pivot table
     */
    public function appointments()
    {
        return $this->belongsToMany(Appointment::class, 'appointment_service', 'service_id', 'appointment_id');
    }
}
