<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class TransactionSeries extends Model implements Auditable
{
    use HasFactory, SoftDeletes, AuditableTrait;

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
