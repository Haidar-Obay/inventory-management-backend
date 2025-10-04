<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Connection extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'type_id'];

    public function type(): BelongsTo
    {
        return $this->belongsTo(ConnectionType::class, 'type_id');
    }
}
