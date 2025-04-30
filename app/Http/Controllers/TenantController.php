<?php

namespace App\Http\Controllers;

use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantCreated;
use App\Exports\ExportPDF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;
use Stancl\Tenancy\Facades\Tenancy;

class TenantController extends Controller
{
    private function isAuthorized()
    {
        $user = auth()->user();
        return in_array($user->role, ['admin', 'owner']);
    }

    public function getAllTenants()
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        $cacheKey = 'central_tenants_all';
        $tenants = tenancy()->central(fn () => Cache::store('database')->get($cacheKey));

        if (!$tenants) {
            $tenants = Tenant::all()->map(function ($tenant) {
                tenancy()->initialize($tenant);
                $owner = User::where('role', 'owner')->first();

                return [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'domain' => optional($tenant->domains->first())->domain,
                    'owner' => optional($owner)->name,
                    'created_at' => $tenant->created_at->toDateTimeString(),
                    'updated_at' => $tenant->updated_at->toDateTimeString(),
                ];
            });

            tenancy()->central(fn () => Cache::store('database')->forever($cacheKey, $tenants));
        }

        return response()->json(["clients" => $tenants]);
    }

    public function store(StoreTenantRequest $request)
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

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

            $user = User::create([
                'name' => "{$request->name}_owner",
                'email' => $request->email,
                'password' => Hash::make($request->password),
                'role' => 'owner',
            ]);

            $user->sendEmailVerificationNotification();
            $user->notify(new TenantCreated($tenant, auth()->user()));
            auth()->user()->notify(new TenantCreated($tenant, auth()->user()));

            tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));

            return response()->json([
                'message' => 'Tenant and owner created successfully!',
                'tenant_id' => $tenant->id,
                'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
                'email' => $tenant->email,
                'name' => $tenant->name,
                'owner' => $user->name,
                'password' => $request->password,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create tenant: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteTenant($id)
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        try {
            $tenant = Tenant::findOrFail($id);
            $tenant->delete();

            tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));

            return response()->json(['message' => 'Tenant deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete tenant: ' . $e->getMessage()], 500);
        }
    }

    public function bulkDeleteTenants(Request $request)
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'exists:tenants,id',
        ]);

        $skipped = [];
        $deleted = 0;

        foreach ($request->ids as $id) {
            try {
                if (tenant('id') === $id) {
                    $skipped[] = ['id' => $id, 'reason' => 'Cannot delete the tenant currently in use.'];
                    continue;
                }

                $tenant = Tenant::find($id);
                if ($tenant) {
                    $tenant->delete();
                    $deleted++;
                }
            } catch (\Illuminate\Database\QueryException $e) {
                $skipped[] = ['id' => $id, 'reason' => 'Deletion failed due to constraints or DB error.'];
            }
        }

        tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));

        return response()->json([
            'message' => 'Bulk tenant deletion completed.',
            'deleted_count' => $deleted,
            'skipped' => $skipped,
        ]);
    }

    public function getTenant($id)
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        $cacheKey = "central_tenant_show_{$id}";

        $tenant = tenancy()->central(fn () => Cache::store('database')->get($cacheKey));

        if (!$tenant) {
            $model = Tenant::with('domains')->find($id);
            if (!$model) {
                return response()->json(['message' => 'Tenant not found'], 404);
            }

            tenancy()->initialize($model);
            $owner = User::where('role', 'owner')->first();

            $tenant = [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'domain' => optional($model->domains->first())->domain,
                'owner' => optional($owner)->name,
                'created_at' => $model->created_at->toDateTimeString(),
                'updated_at' => $model->updated_at->toDateTimeString(),
            ];

            tenancy()->central(fn () => Cache::store('database')->forever($cacheKey, $tenant));
        }

        return response()->json($tenant);
    }

    public function updateTenant(UpdateTenantRequest $request, $id)
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        try {
            $tenant = Tenant::findOrFail($id);

            $tenant->update([
                'name' => $request->name,
                'email' => $request->email,
            ]);

            if ($request->filled('domain')) {
                $tenant->domains()->update([
                    'domain' => "{$request->domain}." . env('CENTRAL_DOMAIN'),
                ]);
            }

            if ($request->filled('password')) {
                $tenant->update([
                    'password' => Hash::make($request->password),
                ]);
            }

            tenancy()->central(function () use ($id) {
                Cache::store('database')->forget("central_tenants_all");
                Cache::store('database')->forget("central_tenant_show_{$id}");
            });

            return response()->json([
                'message' => 'Tenant updated successfully',
                'tenant' => [
                    'id' => $tenant->id,
                    'name' => $tenant->name,
                    'email' => $tenant->email,
                    'password' => $request->password,
                    'domain' => optional($tenant->domains->first())->domain,
                    'updated_at' => $tenant->updated_at->toDateTimeString(),
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update tenant: ' . $e->getMessage()], 500);
        }
    }

    public function exportExcell()
    {
        if (!$this->isAuthorized()) {
            return response()->json(['message' => 'Access denied. Only owner or admin can view tenants.'], 403);
        }

        $query = Tenant::query()
            ->leftJoin('domains', 'tenants.id', '=', 'domains.tenant_id')
            ->select(['tenants.id', 'tenants.name', 'tenants.email', 'domains.domain', 'tenants.created_at', 'tenants.updated_at']);

        if (!$query->exists()) {
            return response()->json(['message' => 'No Tenant found.'], 404);
        }

        return Excel::download(new \App\Exports\Export(
            $query,
            ['id', 'name', 'email', 'domain', 'created_at', 'updated_at'],
            ['ID', 'Name', 'Email', 'Domain', 'Created At', 'Updated At']
        ), 'Tenant.xlsx');
    }

    public function exportPdf(ExportPDF $pdfService)
    {
        $tenants = Tenant::query()
            ->leftJoin('domains', 'tenants.id', '=', 'domains.tenant_id')
            ->select(['tenants.id', 'tenants.name', 'tenants.email', 'domains.domain', 'tenants.created_at', 'tenants.updated_at'])
            ->get();

        if ($tenants->isEmpty()) {
            return response()->json(['message' => 'No tenant found.'], 404);
        }

        $pdf = $pdfService->generatePdf(
            'Tenant Group Report',
            [
                'id' => 'ID',
                'name' => 'Name',
                'email' => 'Email',
                'domain' => 'Domain',
                'created_at' => 'Created At',
                'updated_at' => 'Updated At',
            ],
            $tenants->toArray()
        );

        return $pdf->download('Tenant_Report.pdf');
    }
}
