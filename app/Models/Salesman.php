<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;
use OwenIt\Auditing\Auditable as AuditableTrait;

class Salesman extends Model implements Auditable
{
    use HasFactory, AuditableTrait;

    protected $guarded = ['id'];
    protected $table = 'salesmen';
    protected $primaryKey = 'id';
    public $timestamps = true;

    protected $fillable = [
        'code',
        'name',
        'address',
        'phone1',
        'phone2',
        'email',
        'is_manager',
        'is_supervisor',
        'is_collector',
        'fix_commission',
        'commission_by_item',
        'active'
    ];

    protected $casts = [
        'is_manager' => 'boolean',
        'is_supervisor' => 'boolean',
        'is_collector' => 'boolean',
        'fix_commission' => 'decimal:2',
        'commission_by_item' => 'decimal:2',
        'active' => 'boolean',
    ];

    public function customers()
    {
        return $this->hasMany(Customer::class, 'salesman_id');
    }

    public function customerRoutes()
    {
        return $this->hasMany(CustomerRoute::class, 'salesman_id');
    }

    public function getFullNameAttribute()
    {
        return "{$this->code} - {$this->name}";
    }

    public function isActive()
    {
        return $this->active;
    }

    public function getTotalCommission()
    {
        $total = 0;

        if ($this->fix_commission) {
            $total += $this->fix_commission;
        }

        if ($this->commission_by_item) {
            $total += $this->commission_by_item;
        }

        return $total;
    }
}
