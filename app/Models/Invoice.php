<?php

namespace App\Models;

use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Invoice extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'invoices';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'invoice_number',
        'invoice_type',
        'year',
        'sequence_number',
        'date',
        'due_date',
        'customer_id',
        'supplier_id',
        'currency_id',
        'salesman_id',
        'warehouse_id',
        'payment_term_id',
        'ref_2',
        'sales_order',
        'exchange_rate',
        'discount_2_type',
        'discount_2_value',
        'subtotal',
        'taxes',
        'net_total',
        'adjustment',
        'net_to_pay',
        'notes',
        'billing_to_phones',
        'billing_to_addresses',
        'shipping_to_phones',
        'shipping_to_addresses',
        'customer_name',
        'salesman_name',
        'customer_phone_number',
    ];

    protected $casts = [
        'invoice_type' => InvoiceType::class,
        'year' => 'integer',
        'sequence_number' => 'integer',
        'date' => 'date',
        'due_date' => 'date',
        'discount_2_value' => 'decimal:2',
        'exchange_rate' => 'decimal:4',
        'subtotal' => 'decimal:2',
        'taxes' => 'decimal:2',
        'net_total' => 'decimal:2',
        'adjustment' => 'decimal:2',
        'net_to_pay' => 'decimal:2',
        'billing_to_phones' => 'array',
        'billing_to_addresses' => 'array',
        'shipping_to_phones' => 'array',
        'shipping_to_addresses' => 'array',
    ];

    // Relationships
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function salesman()
    {
        return $this->belongsTo(Salesman::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    // Helper methods
    public function isPurchase(): bool
    {
        return $this->invoice_type === InvoiceType::PURCHASE;
    }

    public function isSale(): bool
    {
        return $this->invoice_type === InvoiceType::SALE;
    }

    /**
     * Calculate due date based on payment term.
     * If payment_term_id is set, calculates: date + payment_term.nb_days
     */
    public function calculateDueDate(): ?\Carbon\Carbon
    {
        if (! $this->payment_term_id || ! $this->date) {
            return null;
        }

        $paymentTerm = $this->paymentTerm;
        if (! $paymentTerm || ! $paymentTerm->nb_days) {
            return null;
        }

        return \Carbon\Carbon::parse($this->date)->addDays($paymentTerm->nb_days);
    }

    /**
     * Recalculate all financial totals based on items.
     * This should be called after items are added/updated.
     * Order: Subtotal → Discount → Tax → Discount2 → Net Total
     */
    public function recalculateTotals(): void
    {
        $items = $this->items;

        // Calculate subtotal from all items (sum of item subtotals)
        $subtotal = $items->sum('subtotal');

        // Calculate total taxes from all items
        // Tax is calculated on (subtotal - discount) for each item
        $taxes = $items->sum(function ($item) {
            $itemSubtotal = $item->quantity * $item->price;
            // Step 1: Apply discount on subtotal
            $itemDiscountAmount = $itemSubtotal * ($item->discount_percent / 100);
            // Step 2: Calculate amount after discount
            $itemAfterDiscount = $itemSubtotal - $itemDiscountAmount;
            // Step 3: Calculate tax on the amount after discount
            $itemTax = $itemAfterDiscount * ($item->tax_percent / 100);

            return $itemTax;
        });

        // Apply document-level discount (discount_2) on subtotal (before taxes)
        $discount2Amount = 0;
        if ($this->discount_2_type && $this->discount_2_value) {
            if ($this->discount_2_type === 'percent') {
                $discount2Amount = $subtotal * ($this->discount_2_value / 100);
            } else {
                $discount2Amount = $this->discount_2_value;
            }
        }

        // Calculate amount after discount2
        $afterDiscount2 = $subtotal - $discount2Amount;

        // Net total = afterDiscount2 + taxes
        $netTotal = $afterDiscount2 + $taxes;

        // Net to pay = net total + adjustment
        $netToPay = $netTotal + ($this->adjustment ?? 0);

        // Update invoice
        $this->update([
            'subtotal' => $subtotal,
            'taxes' => $taxes,
            'net_total' => $netTotal,
            'net_to_pay' => $netToPay,
        ]);
    }
}
