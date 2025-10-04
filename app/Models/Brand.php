<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class Brand extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'brands';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'active' => 'boolean',
    ];

    public function parentBrand()
    {
        return $this->belongsTo(Brand::class, 'sub_brand_of');
    }

    public function subbrands()
    {
        return $this->hasMany(Brand::class, 'sub_brand_of');
    }
}
