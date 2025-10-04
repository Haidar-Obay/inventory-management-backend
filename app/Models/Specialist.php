<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Specialist extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * The specialities that belong to the specialist.
     */
    public function specialities(): BelongsToMany
    {
        return $this->belongsToMany(Speciality::class, 'specialist_speciality')
            ->withTimestamps();
    }

    /**
     * The assets (machines, etc.) this specialist can work on.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_specialist')
            ->withTimestamps();
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'service_specialist')->withTimestamps();
    }
}
