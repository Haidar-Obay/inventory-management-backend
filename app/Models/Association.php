<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Association extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone1', 'phone2', 'email', 'website',
        'markup_value', 'markup_type', 'markdown_value', 'markdown_type',
        'allowed_to_pay_for_guests', 'active',
    ];

    protected $casts = [
        'allowed_to_pay_for_guests' => 'boolean',
        'active' => 'boolean',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(AssociationContact::class);
    }
}
