<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class TaxGroup extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'tax_groups';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'value' => 'decimal:2',
        'active' => 'boolean',
        'default' => 'boolean',
    ];

    // Validation rules for the model
    public static $rules = [
        'code' => 'required|string|max:50|unique:tax_groups,code',
        'name' => 'required|string|max:255',
        'value' => 'required|numeric|min:0|max:100',
        'active' => 'required|boolean',
        'default' => 'nullable|boolean',
    ];
}
