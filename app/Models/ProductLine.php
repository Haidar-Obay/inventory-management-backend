<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class ProductLine extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];

    protected $table = 'product_lines';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * Get the categories for the product line.
     */
    public function categories()
    {
        return $this->hasMany(Category::class, 'product_line_id');
    }
}
