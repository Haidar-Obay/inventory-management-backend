<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

class CompanyCode extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $guarded = ['id'];

    protected $table = 'company_codes';

    protected $primaryKey = 'id';

    public $timestamps = true;

    // Customer relationship (one-to-many)
    public function customers()
    {
        return $this->hasMany(Customer::class, 'company_code_id');
    }
}
