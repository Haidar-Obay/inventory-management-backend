<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\Tenant;
use Illuminate\Support\Facades\Notification;
use Stancl\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Notifications\TenantCreated;

class TenantController extends Controller
{
    public function getAllTenants()
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Only admins can view tenants.'], 403);
        }

        $tenants = Tenant::all()->map(function ($tenant) {
            tenancy()->initialize($tenant);
            $admin = User::where('role', 'admin')->first();
            return [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'email' => $tenant->email,
                'domain' => $tenant->domains->first()->domain ?? null,
                'admin' => $admin?->name ?? null,
                'created_at' => $tenant->created_at->toDateTimeString(),
                'updated_at' => $tenant->updated_at->toDateTimeString(),
            ];
        });

        return response()->json(["clients" => $tenants]);
    }

    public function store(StoreTenantRequest $request)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Only admins can create tenants.'], 403);
        }

        try {
            $email = $request->email;
            $url = "https://apilayer.net/api/check?access_key=774df7c6873b3b081fb76f9e71580f93&email={$email}&smtp=1&format=1";
            $response = Http::get($url);

            if ($response->successful()) {
                $data = $response->json();

                if (!($data['format_valid'] && $data['mx_found'] && $data['smtp_check'])) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Email appears to be invalid or unreachable.',
                    ], 422);
                }
            } else {
                return response()->json([
                    'status' => false,
                    'message' => 'Could not validate email address. Try again later.',
                ], 500);
            }

            $tenant = Tenant::create([
                'id' => $request->domain,
                'name' => $request->name,
                'email' => $request->email,
            ]);

            $tenant->domains()->create([
                'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
            ]);

            tenancy()->initialize($tenant);

            $user = User::create([
                'name' => $request->name . '_admin',
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'admin',
            ]);

            $admins = User::where('role', 'admin')->get();
            Notification::send(
                $admins,
                new TenantCreated($tenant, $user)
            );

            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => 'Tenant and admin created successfully',
                'tenant_id' => $tenant->id,
                'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
                'email' => $tenant->email,
                "admin" => User::firstWhere('role', 'admin')->name,
                "password" => $request->password
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create tenant: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteTenant($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Only admins can delete tenants.'], 403);
        }

        try {
            $tenant = Tenant::findOrFail($id);
            $tenant->delete();
            return response()->json(['message' => 'Tenant deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete tenant: ' . $e->getMessage()], 500);
        }
    }

    public function getTenant($id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Only admins can view tenant details.'], 403);
        }

        $tenant = Tenant::with('domains')->find($id);

        if (!$tenant) {
            return response()->json(['message' => 'Tenant not found'], 404);
        }

        tenancy()->initialize($tenant);
        $admin = User::where('role', 'admin')->first();

        return response()->json([
            'id' => $tenant->id,
            'name' => $tenant->name,
            'email' => $tenant->email,
            'domain' => optional($tenant->domains->first())->domain,
            'admin' => $admin?->name ?? null,
            'created_at' => $tenant->created_at->toDateTimeString(),
            'updated_at' => $tenant->updated_at->toDateTimeString(),
        ]);
    }

    public function updateTenant(UpdateTenantRequest $request, $id)
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Only admins can update tenants.'], 403);
        }

        try {
            $tenant = Tenant::findOrFail($id);

            $tenant->update([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ]);

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

    public function exportExcell()
    {
        if (auth()->user()->role !== 'admin') {
            return response()->json(['message' => 'Access denied. Only admins can export tenants.'], 403);
        }

        $query = Tenant::query()
            ->leftJoin('domains', 'tenants.id', '=', 'domains.tenant_id')
            ->select([
                'tenants.id as id',
                'tenants.name as name',
                'tenants.email as email',
                'domains.domain as domain',
                'tenants.created_at as created_at',
                'tenants.updated_at as updated_at',
            ]);

        if (!$query->exists()) {
            return response()->json(['message' => 'No Tenant found.'], 404);
        }

        $columns = [
            'id',
            'name',
            'email',
            'domain',
            'created_at',
            'updated_at',
        ];

        $headings = [
            'ID',
            'Name',
            'Email',
            'Domain',
            'Created At',
            'Updated At',
        ];

        return Excel::download(new \App\Exports\Export($query, $columns, $headings), 'Tenant.xlsx');
    }
}
