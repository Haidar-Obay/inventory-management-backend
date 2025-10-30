<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class UnitOfMeasurement extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'unit_of_measurements';

    protected $fillable = [
        'name',
        'unit_group_id',
        'operation',
        'conversion',
    ];

    protected $casts = [
        'conversion' => 'decimal:4',
    ];

    public function unitGroup()
    {
        return $this->belongsTo(UnitGroup::class);
    }

    public function items()
    {
        return $this->belongsToMany(Item::class, 'item_unit_of_measurement')
            ->using(ItemUnitOfMeasurement::class)
            ->withPivot([
                'barcodes',
                'price_1', 'price_2', 'price_3', 'price_4', 'price_5', 'price_6',
                'gross_volume', 'gross_weight',
                'net_volume', 'net_weight',
            ])
            ->withTimestamps();
    }

    /**
     * Calculate conversion value
     */
    public function convert($value)
    {
        return $this->operation === 'multiply'
            ? $value * $this->conversion
            : $value / $this->conversion;
    }

    /**
     * Reverse conversion (convert back to base unit)
     */
    public function reverseConvert($value)
    {
        return $this->operation === 'multiply'
            ? $value / $this->conversion
            : $value * $this->conversion;
    }
}
