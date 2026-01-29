<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class SubCategory extends Model implements Auditable
{
    use AuditableTrait, SoftDeletes;

    protected $guarded = ['id'];

    protected $table = 'sub_categories';

    protected $primaryKey = 'id';

    public $timestamps = true;

    /**
     * The category this subcategory belongs to.
     */
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
}
