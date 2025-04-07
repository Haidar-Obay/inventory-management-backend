<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Stancl\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Hash;
use App\Models\User; 

class TenantController extends Controller
{

    //method for creating tenant
   
public function store(Request $request)
{
    try {
        // 1. Create the tenant record
        $tenant = Tenant::create([
            'id' => $request->subdomain,
            'data' => [
                'name' => $request->name,
                'email' => $request->email,
            ],
        ]);

        // 2. Create a domain record for the tenant
        $tenant->domains()->create([
            'domain' => "{$request->subdomain}.yourdomain.test",
        ]);

        // 3. Initialize tenancy context
        tenancy()->initialize($tenant);

        // 5. Create the super user in the tenant's DB
        User::create([
            'name' => 'Admin',
            'email' => $request->email,
            'password' => Hash::make('password'), // Or use $request->password
            'role' => 'super_user', // Make sure 'role' column exists
        ]);

        // 6. Return success
        return response()->json([
            'message' => 'Tenant and super user created successfully',
            'tenant_id' => $tenant->id,
            'domain' => "{$request->subdomain}.yourdomain.test",
        ], 201);

    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Failed to create tenant: ' . $e->getMessage(),
        ],500);
    }
}

    //method for deleting tenant
    public function deleteTenant($id)
    {

        try {
            $tenant = Tenant::findOrFail($id);
            Tenancy::find($id)->delete();
            return response()->json(['message' => 'Tenant deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete tenant: ' . $e->getMessage()], 500);
        }
    }
}
