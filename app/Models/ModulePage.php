<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModulePage extends Model
{
    protected $fillable = [
        'module_id', 'name', 'code', 'path', 'order', 'is_public',
    ];

    protected $casts = [
        'order' => 'integer',
        'is_public' => 'boolean',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
