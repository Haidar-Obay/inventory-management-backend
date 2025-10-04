<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class AdjustmentType extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'adjustment_types';

    protected $primaryKey = 'id';

    public $timestamps = true;

    protected $casts = [
        'need_supplier' => 'boolean',
        'need_customer' => 'boolean',
        'need_employee' => 'boolean',
        'need_second_warehouse' => 'boolean',
    ];

    // Validation rules for the model
    public static $rules = [
        'code' => 'required|string|max:50|unique:adjustment_types,code',
        'name' => 'required|string|max:255',
        'cost_type' => 'required|string|max:50',
        'need_supplier' => 'required|boolean',
        'need_customer' => 'required|boolean',
        'need_employee' => 'required|boolean',
        'need_second_warehouse' => 'required|boolean',
        'transaction_type' => 'required|in:in,out,in_out',
    ];

    // Constants for transaction types
    const TRANSACTION_TYPE_IN = 'in';

    const TRANSACTION_TYPE_OUT = 'out';

    const TRANSACTION_TYPE_IN_OUT = 'in_out';

    // Helper method to get all transaction types
    public static function getTransactionTypes()
    {
        return [
            self::TRANSACTION_TYPE_IN => 'In',
            self::TRANSACTION_TYPE_OUT => 'Out',
            self::TRANSACTION_TYPE_IN_OUT => 'In/Out',
        ];
    }
}
