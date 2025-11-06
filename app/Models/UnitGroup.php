<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

class UnitGroup extends Model implements Auditable
{
    use AuditableTrait, HasFactory;

    protected $guarded = ['id'];

    protected $table = 'unit_groups';

    protected $fillable = [
        'name',
    ];

    public function unitOfMeasurements()
    {
        return $this->hasMany(UnitOfMeasurement::class);
    }
}
