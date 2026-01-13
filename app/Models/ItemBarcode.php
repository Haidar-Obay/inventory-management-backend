<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ItemBarcode extends Model
{
    protected $table = 'item_barcodes';

    protected $fillable = [
        'item_id',
        'item_unit_of_measurement_id',
        'barcode',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    /**
     * Get the item that owns this barcode
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /**
     * Get the item unit of measurement that owns this barcode
     */
    public function itemUnitOfMeasurement(): BelongsTo
    {
        return $this->belongsTo(ItemUnitOfMeasurement::class, 'item_unit_of_measurement_id');
    }
}
