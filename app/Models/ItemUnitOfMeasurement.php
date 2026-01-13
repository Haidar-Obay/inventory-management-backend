<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

class ItemUnitOfMeasurement extends Pivot
{
    protected $table = 'item_unit_of_measurement';

    protected $fillable = [
        'item_id',
        'unit_of_measurement_id',
        'operation',
        'conversion',
        'price_1', 'price_2', 'price_3', 'price_4', 'price_5', 'price_6',
        'gross_volume', 'gross_weight',
        'net_volume', 'net_weight',
    ];

    protected $casts = [
        'conversion' => 'decimal:4',
        'price_1' => 'decimal:2',
        'price_2' => 'decimal:2',
        'price_3' => 'decimal:2',
        'price_4' => 'decimal:2',
        'price_5' => 'decimal:2',
        'price_6' => 'decimal:2',
        'gross_volume' => 'decimal:4',
        'gross_weight' => 'decimal:4',
        'net_volume' => 'decimal:4',
        'net_weight' => 'decimal:4',
    ];

    /**
     * Get all barcodes for this item unit of measurement
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ItemBarcode::class, 'item_unit_of_measurement_id');
    }
}
