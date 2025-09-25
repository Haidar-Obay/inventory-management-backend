<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceAdvancedPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'specialist_id',
        'price_on_site',
        'price_on_call',
    ];

    protected $casts = [
        'price_on_site' => 'decimal:2',
        'price_on_call' => 'decimal:2',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function specialist(): BelongsTo
    {
        return $this->belongsTo(Specialist::class);
    }
}


