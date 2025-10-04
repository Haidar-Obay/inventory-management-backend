<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssociationContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'association_id', 'contact_name', 'contact_phone', 'contact_email',
    ];

    public function association(): BelongsTo
    {
        return $this->belongsTo(Association::class);
    }
}
