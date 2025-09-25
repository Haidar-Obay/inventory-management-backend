<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Speciality extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
    ];

    /**
     * Specialists that have this speciality.
     */
    public function specialists(): BelongsToMany
    {
        return $this->belongsToMany(Specialist::class, 'specialist_speciality')
            ->withTimestamps();
    }
}


