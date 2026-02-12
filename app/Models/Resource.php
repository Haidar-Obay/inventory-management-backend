<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Resource extends Model
{
    protected $fillable = [
        'name',
        'code',
        'description',
        'migration_class',
        'enabled',
        'version',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'version' => 'integer',
    ];

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(Module::class, 'module_resources')
            ->withTimestamps();
    }
}
