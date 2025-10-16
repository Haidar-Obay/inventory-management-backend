<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModuleResource extends Model
{
    protected $fillable = [
        'module_id', 'name', 'code', 'description', 'migration_class', 'enabled', 'version',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'version' => 'integer',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(Module::class);
    }
}
