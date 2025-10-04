<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class SupplierChequeLimit extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'supplier_cheque_limits';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'max_cheques' => 'integer',
        'used_cheques' => 'integer',
        'available_cheques' => 'integer',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        $query->where('is_active', true);
    }

    public function scopeForCurrency($query, $currencyId)
    {
        $query->where('currency_id', $currencyId);
    }

    // Helper methods
    public function updateAvailableCheques()
    {
        $this->available_cheques = $this->max_cheques - $this->used_cheques;
        $this->save();
    }

    public function hasAvailableCheques($count = 1)
    {
        return $this->available_cheques >= $count;
    }

    public function getUtilizationPercentage()
    {
        if ($this->max_cheques == 0) {
            return 0;
        }

        return ($this->used_cheques / $this->max_cheques) * 100;
    }

    public function isOverLimit()
    {
        return $this->used_cheques > $this->max_cheques;
    }

    public function getRemainingCheques()
    {
        return max(0, $this->max_cheques - $this->used_cheques);
    }

    // Boot method to automatically update available_cheques
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            $model->available_cheques = $model->max_cheques - $model->used_cheques;
        });
    }
}
