<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class CustomerMasterList extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'customer_master_lists';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'date',
        'name',
        'valid_from',
        'valid_till'
    ];

    protected $casts = [
        'date' => 'date',
        'valid_from' => 'date',
        'valid_till' => 'date',
    ];

    // Many-to-many relationship with items
    public function items()
    {
        return $this->belongsToMany(Item::class, 'customer_master_list_item')
                    ->withPivot(['price', 'discount'])
                    ->withTimestamps();
    }

    // Scope for active master lists (within valid date range)
    public function scopeActive($query)
    {
        return $query->where('valid_from', '<=', now()->toDateString())
                    ->where('valid_till', '>=', now()->toDateString());
    }

    // Scope for valid on specific date
    public function scopeValidOn($query, $date)
    {
        return $query->where('valid_from', '<=', $date)
                    ->where('valid_till', '>=', $date);
    }

    // Check if the master list is currently active
    public function isActive()
    {
        $today = now()->toDateString();
        return $this->valid_from <= $today && $this->valid_till >= $today;
    }

    // Pricing now lives on pivot; header has no price/discount accessors
}
