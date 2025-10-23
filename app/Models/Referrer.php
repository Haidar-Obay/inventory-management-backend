<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Referrer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'address', 'phone1', 'phone2', 'email', 'active', 'commission_percent',
    ];

    protected $casts = [
        'active' => 'boolean',
        'commission_percent' => 'decimal:2',
    ];

    // Customer relationship (one-to-many)
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'referral_id');
    }
}
