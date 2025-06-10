<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Warehouse extends Model implements Auditable
{
    use HasFactory, SoftDeletes, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'warehouses';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $casts = [
        'is_inactive' => 'boolean',
    ];

    // Validation rules for the model
    public static $rules = [
        'code' => 'required|string|max:50|unique:warehouses,code',
        'name' => 'required|string|max:255',
        'sub_warehouse_of' => 'nullable|exists:warehouses,id',
        'is_inactive' => 'required|boolean',
    ];

    // Self-referential relationship for parent warehouse
    public function parentWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'sub_warehouse_of');
    }

    // Self-referential relationship for sub-warehouses
    public function subWarehouses()
    {
        return $this->hasMany(Warehouse::class, 'sub_warehouse_of');
    }

    // Helper method to get all sub-warehouses recursively
    public function getAllSubWarehouses()
    {
        $subWarehouses = $this->subWarehouses;

        foreach ($this->subWarehouses as $subWarehouse) {
            $subWarehouses = $subWarehouses->merge($subWarehouse->getAllSubWarehouses());
        }

        return $subWarehouses;
    }

    // Helper method to check if a warehouse is a sub-warehouse of another
    public function isSubWarehouseOf(Warehouse $warehouse)
    {
        $parent = $this->parentWarehouse;

        while ($parent) {
            if ($parent->id === $warehouse->id) {
                return true;
            }
            $parent = $parent->parentWarehouse;
        }

        return false;
    }
}
