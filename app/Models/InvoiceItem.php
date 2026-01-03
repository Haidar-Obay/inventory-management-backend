<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class InvoiceItem extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'invoice_items';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'invoice_id',
        'item_id',
        'barcode',
        'description',
        'uom_id',
        'warehouse_id',
        'quantity',
        'price',
        'unit_price',
        'discount_percent',
        'tax_percent',
        'subtotal',
        'total',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'price' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'discount_percent' => 'decimal:2',
        'tax_percent' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    // Relationships
    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(UnitOfMeasurement::class, 'uom_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * Calculate and update item totals.
     * This should be called when quantity, price, discount_percent, or tax_percent changes.
     */
    public function calculateTotals(): void
    {
        // Subtotal = quantity * price
        $subtotal = $this->quantity * $this->price;

        // Apply discount
        $discountAmount = $subtotal * ($this->discount_percent / 100);
        $afterDiscount = $subtotal - $discountAmount;

        // Apply tax
        $taxAmount = $afterDiscount * ($this->tax_percent / 100);
        $total = $afterDiscount + $taxAmount;

        $this->update([
            'subtotal' => $subtotal,
            'total' => $total,
        ]);

        // Recalculate invoice totals
        $this->invoice->recalculateTotals();
    }

    /**
     * Calculate unit price based on UOM conversion.
     * Unit price = price / conversion_factor (where conversion is number of base units)
     *
     * @param  float  $price  The price of the selected UOM
     * @param  float  $conversion  The conversion factor from item_unit_of_measurement
     * @return float The base unit price
     */
    public static function calculateUnitPrice(float $price, float $conversion): float
    {
        if ($conversion <= 0) {
            return $price; // Fallback to price if conversion is invalid
        }

        return $price / $conversion;
    }
}
