<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class Branch extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'branches';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'is_head_office' => 'boolean',
        'is_storage' => 'boolean',
        'is_inactive' => 'boolean',
    ];

    // Validation rules for the model
    public static $rules = [
        'code' => 'required|string|max:50|unique:branches,code',
        'name' => 'required|string|max:255',
        'address' => 'nullable|string',
        'group' => 'nullable|string|max:100',
        'contact' => 'nullable|string|max:100',
        'phone_1' => 'nullable|string|max:20',
        'phone_2' => 'nullable|string|max:20',
        'email' => 'nullable|email|max:100',
        'po_box' => 'nullable|string|max:50',
        'postal_code' => 'nullable|string|max:20',
        'is_head_office' => 'required|boolean',
        'is_storage' => 'required|boolean',
        'is_inactive' => 'required|boolean',
        'cash_account' => 'nullable|string|max:50',
        'bank_account' => 'nullable|string|max:50',
    ];
}
