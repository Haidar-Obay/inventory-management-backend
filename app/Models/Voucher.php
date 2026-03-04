<?php

namespace App\Models;

use App\Enums\VoucherType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Voucher extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'vouchers';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'voucher_number',
        'type',
        'year',
        'sequence_number',
        'date',
        'effective_date',
        'ref_2',
        'currency_id',
        'exchange_rate',
        'customer_id',
        'supplier_id',
        'customer_name',
        'supplier_name',
        'opening_balance_currency_id',
        'opening_balance_amount',
        'amount',
        'salesman_id',
        'collector_id',
        'total_voucher',
        'total_paid',
        'total_difference',
        'notes',
    ];

    protected $casts = [
        'type' => VoucherType::class,
        'year' => 'integer',
        'sequence_number' => 'integer',
        'date' => 'date',
        'effective_date' => 'date',
        'exchange_rate' => 'decimal:4',
        'opening_balance_amount' => 'decimal:2',
        'amount' => 'decimal:2',
        'total_voucher' => 'decimal:2',
        'total_paid' => 'decimal:2',
        'total_difference' => 'decimal:2',
    ];

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

    public function openingBalanceCurrency()
    {
        return $this->belongsTo(Currency::class, 'opening_balance_currency_id');
    }

    public function salesman()
    {
        return $this->belongsTo(Salesman::class);
    }

    public function collector()
    {
        return $this->belongsTo(Salesman::class, 'collector_id');
    }

    public function lines()
    {
        return $this->hasMany(VoucherLine::class);
    }

    public function isReceipt(): bool
    {
        return $this->type === VoucherType::RECEIPT;
    }

    public function isPayment(): bool
    {
        return $this->type === VoucherType::PAYMENT;
    }

    /**
     * Recalculate total_voucher, total_paid, total_difference from lines.
     */
    public function recalculateTotals(): void
    {
        $totalVoucher = $this->lines()->sum('amount');
        $this->update([
            'total_voucher' => $totalVoucher,
            'total_paid' => $totalVoucher,
            'total_difference' => ($this->amount ?? 0) - $totalVoucher,
        ]);
    }
}
