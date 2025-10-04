<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class TransactionSeries extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'transaction_series';

    protected $primaryKey = 'id';

    public $timestamps = true;

    public function companyCode()
    {
        return $this->belongsTo(CompanyCode::class);
    }

    public function trade()
    {
        return $this->belongsTo(Trade::class);
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function getTemplateWithCompanyAttribute()
    {
        return "{$this->template} - {$this->companyCode->name}";
    }
}
