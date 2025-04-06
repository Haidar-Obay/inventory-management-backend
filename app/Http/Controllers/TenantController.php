<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    public function store(Request $request)
    {

        // $request->validate([
        //     'name' => 'required|string',
        //     'email' => 'required|email',
        //     'subdomain' => 'required|string|unique:tenants,id',
        // ]);


        $tenant = Tenant::create([
            'id' => $request->subdomain, // Or UUID
            'data' => [
                'name' => $request->name,
                'email' => $request->email,
            ],
        ]);

        $tenant->domains()->create([
            'domain' => "{$request->subdomain}.yourdomain.test",
        ]);

        return response()->json([
        'message' => 'Tenant created successfully',
        'tenant_id' => $tenant->id,
        'domain' => "{$request->subdomain}.yourdomain.test"
        ]);    }
}
