<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}


