<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\Tenant;
use Stancl\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class TenantController extends Controller
{
    //method for getting all tenants
    public function getAllTenants()
    {
        $tenants = Tenant::all()->map(function ($tenant) {
            tenancy()->initialize($tenant); 
            $superUser = User::where('role', 'super_user')->first();
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'domain' => $tenant->domains->first()->domain ?? null,
                'super_user' => $superUser?->name ?? null,
                'created_at' => $tenant->created_at->toDateTimeString(),
                'updated_at' => $tenant->updated_at->toDateTimeString(),
            ];
        });

        return response()->json(["clients" => $tenants]);
    }
    //method for creating tenant
    public function store(StoreTenantRequest $request)
    {
        try {
            $tenant = Tenant::create([
                'id' => $request->domain,
                'name' => $request->name,
                'email' => $request->email,
            ]);

            $tenant->domains()->create([
                'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
            ]);

            tenancy()->initialize($tenant);

            User::create([
                'name' => 'Admin',
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'super_user',
            ]);

            return response()->json([
                'message' => 'Tenant and super user created successfully',
                'tenant_id' => $tenant->id,
                'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
                'email' => $tenant->email,
                "super_user" => User::firstWhere('role', 'super_user')->name,
                "password" => $request->password
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
            $tenant->delete();
            return response()->json(['message' => 'Tenant deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete tenant: ' . $e->getMessage()], 500);
        }
    }

    //method for getting single tenant
    public function getTenant($id)
    {
        $tenant = Tenant::with('domains')->find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        tenancy()->initialize($tenant);
        $superUser = User::where('role', 'super_user')->first();

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email' => $tenant->email,
            'domain' => optional($tenant->domains->first())->domain,
            'super_user' => $superUser?->name ?? null,
            'created_at' => $tenant->created_at->toDateTimeString(),
            'updated_at' => $tenant->updated_at->toDateTimeString(),

        ]);
    }

    //method for updating tenant
    public function updateTenant(UpdateTenantRequest $request, $id)
    {
        try {
            $tenant = Tenant::findOrFail($id);

            // Update name and email
            $tenant->update([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ]);
            // Update domain if provided
            if ($request->filled('domain')) {
                $tenant->domains()->update([
                    'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
                ]);
            }

            if ($request->filled('password')) {
                $tenant->update([
                    'password' => Hash::make($request->input('password')),
                ]);
            }

            return response()->json([
                'message' => 'Tenant updated successfully',
                'tenant' => [
                        'id' => $tenant->id,
                        'name' => $tenant->name,
                        'email' => $tenant->email,
                        'password' => $request->input('password'),
                        'domain' => optional($tenant->domains->first())->domain,
                        'updated_at' => $tenant->updated_at->toDateTimeString(),
                    ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to update tenant: ' . $e->getMessage(),
            ], 500);
        }
    }
}
