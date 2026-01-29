<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Category extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'categories';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'is_inactive' => 'boolean',
    ];

    /**
     * Get the product line that owns the category.
     */
    public function productLine()
    {
        return $this->belongsTo(ProductLine::class, 'product_line_id');
    }

    /**
     * SubCategory records where this category is the parent (name + category_id).
     */
    public function subCategoryLinks()
    {
        return $this->hasMany(SubCategory::class, 'category_id');
    }

    /**
     * Subcategories under this category (SubCategory models with name, category_id).
     */
    public function subcategories()
    {
        return $this->hasMany(SubCategory::class, 'category_id');
    }
}
