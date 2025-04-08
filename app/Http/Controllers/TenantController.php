<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Stancl\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class TenantController extends Controller
{
    public function getAllTenants()
    {
        $tenants = Tenant::all()->map(function ($tenant) {
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'created_at' => $tenant->created_at->toDateTimeString(),
                'updated_at' => $tenant->updated_at->toDateTimeString(),
                'subdomain' => $tenant->domains->first()->domain ?? null,
            ];
        });

        return response()->json(["clients" => $tenants]);
    }
    //method for creating tenant
    public function store(Request $request)
    {
        try {
            // 1. Create the tenant record
            $tenant = Tenant::create([
                'id' => $request->subdomain,
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ]);

            // 2. Create a domain record for the tenant
            $tenant->domains()->create([
                'domain' => "{$request->subdomain}." . env('CENTRAL_DOMAIN'),
            ]);

            // 3. Initialize tenancy context
            tenancy()->initialize($tenant);

            // 5. Create the super user in the tenant's DB
            User::create([
                'name' => 'Admin',
                'email' => $request->email,
                'password' => Hash::make('password'), // Or use $request->password
                'role' => 'super_user', // Make sure 'role' column exists
                'tenant_id' => $tenant->id,
            ]);

            // 6. Return success
            return response()->json([
                'message' => 'Tenant and super user created successfully',
                'tenant_id' => $tenant->id,
                'domain' => "{$request->subdomain}." . env('CENTRAL_DOMAIN'),
            ], 201);

        } catch (\Exception $e) {
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

    public function getTenant($id)
    {

        $tenant = Tenant::with('domains')->find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email' => $tenant->email,
            'created_at' => $tenant->created_at->toDateTimeString(),
            'updated_at' => $tenant->updated_at->toDateTimeString(),
            'subdomain' => optional($tenant->domains->first())->domain,
        ]);
    }

    public function updateTenant(Request $request, $id)
    {
        $request->validate([
            'name' => 'required|string',
            'email' => 'required|email',
            'subdomain' => 'nullable|string|unique:domains,domain'
        ]);

        try {
            $tenant = Tenant::findOrFail($id);

            // Update name and email
            $tenant->update([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ]);

            if ($request->filled('subdomain')) {
                $tenant->domains()->update([
                    'domain' => "{$request->subdomain}." . env('CENTRAL_DOMAIN'),
                ]);
            }

            return response()->json([
                'message' => 'Tenant updated successfully',
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'updated_at' => $tenant->updated_at->toDateTimeString(),
                    'subdomain' => optional($tenant->domains->first())->domain,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update tenant: ' . $e->getMessage(),
            ], 500);
        }
    }
}
