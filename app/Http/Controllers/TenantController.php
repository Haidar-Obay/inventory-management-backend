<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Stancl\Tenancy\Facades\Tenancy;

class TenantController extends Controller
{

    //method for creating tenant
    public function store(Request $request)
    {

        try {
            // Create the tenant record in the tenants table
            $tenant = Tenant::create([
                'id' => $request->subdomain, // Tenant ID (subdomain)
                'data' => [
                    'name' => $request->name,    // Name
                    'email' => $request->email,  // Email
                ],
            ]);

            // Create a domain record for the tenant (optional)
            $tenant->domains()->create([
                'domain' => "{$request->subdomain}.yourdomain.test", // Assign domain
            ]);

            // Return success response
            return response()->json([
                'message' => 'Tenant created successfully',
                'tenant_id' => $tenant->id,
                'domain' => "{$request->subdomain}.yourdomain.test",
            ], 201);
        } catch (\Exception $e) {
            // Handle any exceptions during tenant creation
            return response()->json([
                'error' => 'Failed to create tenant: ' . $e->getMessage(),
            ], 500);
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
