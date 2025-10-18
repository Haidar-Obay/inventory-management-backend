<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Item extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'items';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'price',
        'unit',
        'description',
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

    // Many-to-many relationship with customer master lists
    public function customerMasterLists()
    {
        return $this->belongsToMany(CustomerMasterList::class, 'customer_master_list_item')
            ->withTimestamps();
    }
}
