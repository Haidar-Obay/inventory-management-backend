<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class ProductLine extends Model implements Auditable
{
    use AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'product_lines';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'is_inactive' => 'boolean',
    ];
}
