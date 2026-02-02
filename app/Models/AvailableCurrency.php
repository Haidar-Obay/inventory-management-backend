<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AvailableCurrency extends Model
{
    protected $fillable = [
        'code',
        'name',
        'iso_code',
        'symbol',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Scope a query to only include active currencies.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
