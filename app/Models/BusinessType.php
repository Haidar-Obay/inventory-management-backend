<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class BusinessType extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'business_types';
    protected $primaryKey = 'id';
    public $timestamps = true;

    // Validation rules for the model
    public static $rules = [
        'code' => 'required|string|max:50|unique:business_types,code',
        'name' => 'required|string|max:255',
    ];
}
