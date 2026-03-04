<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class VoucherLine extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'voucher_lines';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'voucher_id',
        'cash_account_id',
        'currency_id',
        'exchange_rate',
        'amount',
        'remark',
    ];

    protected $casts = [
        'exchange_rate' => 'decimal:4',
        'amount' => 'decimal:2',
    ];

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(CashAccount::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }
}
