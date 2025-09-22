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
        'asset_id',
        'description',
        'unit',
        'qty',
        'notes_multiline',
    ];

    protected $casts = [
        'qty' => 'decimal:3',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}


