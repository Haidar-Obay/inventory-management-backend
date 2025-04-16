<?php

namespace App\Models;

use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use OwenIt\Auditing\Contracts\Auditable;

class Tenant extends BaseTenant implements TenantWithDatabase, Auditable
{
    use \OwenIt\Auditing\Auditable;
    use HasDatabase, HasDomains;
    protected $fillable = ['id', 'name', 'email'];

    public function users()
    {
        return $this->hasMany(User::class );
    }

}
