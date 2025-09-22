<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReferrerServiceCommission extends Model
{
    use HasFactory;

    protected $fillable = [
        'referrer_id', 'service_id', 'price_override', 'discount_override', 'commission_percent',
    ];

    protected $casts = [
        'price_override' => 'decimal:2',
        'discount_override' => 'decimal:2',
        'commission_percent' => 'decimal:2',
    ];

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}


