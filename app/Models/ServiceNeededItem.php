<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceNeededItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'item_id',
        'description',
        'quantity',
    ];

    protected $casts = [
        'quantity' => 'decimal:3',
    ];

    protected $attributes = [
        'quantity' => 0,
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
