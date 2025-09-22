<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceCategory extends Model
{
    use HasFactory;

    protected $fillable = [
        'service_id',
        'categories',
    ];

    protected $casts = [
        'categories' => 'array',
    ];

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}


