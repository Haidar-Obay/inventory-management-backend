<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Category extends Model implements Auditable
{
    use AuditableTrait, SoftDeletes;

    protected $guarded = ['id'];
    protected $table = 'categories';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'is_inactive' => 'boolean',
    ];

    /**
     * Get the parent category of this category.
     */
    public function parentCategory()
    {
        return $this->belongsTo(Category::class, 'subcategory_of');
    }

    /**
     * Get the subcategories of this category.
     */
    public function subcategories()
    {
        return $this->hasMany(Category::class, 'subcategory_of');
    }
}
