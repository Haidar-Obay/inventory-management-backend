<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Item extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'items';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'price'
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function getFormattedPriceAttribute()
    {
        return number_format($this->price, 2);
    }
} 