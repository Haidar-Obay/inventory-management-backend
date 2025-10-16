<?php

namespace App\Http\Controllers;

use App\Exports\ExportPDF;
use App\Http\Requests\Tenant\StoreTenantRequest;
use App\Http\Requests\Tenant\UpdateTenantRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\TenantCreated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Maatwebsite\Excel\Facades\Excel;

class TenantController extends Controller
{
    private function isAuthorized()
    {
        // Note: Authorization now handled by middleware/permissions
        return true;
    }

    public function getAllTenants()
    {
        if (! $this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        $cacheKey = 'central_tenants_all';
        $tenants = tenancy()->central(fn () => Cache::store('database')->get($cacheKey));

        if (! $tenants) {
            $tenants = Tenant::all()->map(function ($tenant) {
                tenancy()->initialize($tenant);
                $owner = User::first(); // Note: Owner identification now via roles table

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

        return response()->json(['clients' => $tenants]);
    }

    public function store(StoreTenantRequest $request)
    {
        if (! $this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        try {
            // Get subscription plan (user choice or default)
            $subscriptionPlan = null;
            if ($request->filled('subscription_plan_id')) {
                $subscriptionPlan = \App\Models\SubscriptionPlan::where('id', $request->input('subscription_plan_id'))
                    ->where('is_active', true)
                    ->first();

                if (! $subscriptionPlan) {
                    return response()->json([
                        'error' => 'Selected subscription plan not found or inactive.',
                    ], 422);
                }
            } else {
                // Fallback to default plan if no plan specified
                $subscriptionPlan = \App\Models\SubscriptionPlan::where('is_default', true)
                    ->where('is_active', true)
                    ->first();

                if (! $subscriptionPlan) {
                    return response()->json([
                        'error' => 'No subscription plan specified and no default plan found. Please create a default plan or specify a plan.',
                    ], 500);
                }
            }

            // Prepare tenant data with user choices
            $tenantData = [
                'id' => $request->input('domain'),
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                'subscription_plan_id' => $subscriptionPlan->id,
            ];

            // Add subscription fields if provided, otherwise use defaults
            if ($request->filled('subscription_start_date')) {
                $tenantData['subscription_start_date'] = $request->input('subscription_start_date');
            } else {
                $tenantData['subscription_start_date'] = now();
            }

            if ($request->filled('subscription_end_date')) {
                
            } else {
                // If no end date specified, add 30 days from start date
                $startDate = $tenantData['subscription_start_date'];
                $tenantData['subscription_end_date'] = is_string($startDate)
                    ? \Carbon\Carbon::parse($startDate)->addDays(30)
                    : $startDate->addDays(30);
            }

            if ($request->filled('subscription_status')) {
                $tenantData['subscription_status'] = $request->input('subscription_status');
            } else {
                $tenantData['subscription_status'] = 'trial';
            }

            if ($request->filled('auto_renew')) {
                $tenantData['auto_renew'] = $request->input('auto_renew');
            } else {
                $tenantData['auto_renew'] = false;
            }

            if ($request->filled('last_billing_date')) {
                $tenantData['last_billing_date'] = $request->input('last_billing_date');
            }

            if ($request->filled('next_billing_date')) {
                $tenantData['next_billing_date'] = $request->input('next_billing_date');
            }

            if ($request->filled('data')) {
                $tenantData['data'] = $request->input('data');
            }

            $tenant = Tenant::create($tenantData);

            $tenant->domains()->create([
                'domain' => "{$request->input('domain')}.".env('CENTRAL_DOMAIN'),
            ]);

            tenancy()->initialize($tenant);

            $user = User::create([
                'name' => "{$request->input('name')}_owner",
                'email' => $request->input('email'),
                'password' => Hash::make($request->input('password')),
                'active' => true,
            ]);

            // Assign modules to tenant with provided selection (no plan-module dependency)
            if ($request->filled('selected_modules')) {
                $moduleIds = $request->input('selected_modules', []);
                $syncData = [];

                foreach ($moduleIds as $moduleId) {
                    $syncData[$moduleId] = [
                        'assigned_price' => 0.0, // default; can be updated later via billing config
                        'is_included' => false,
                        'subscription_plan_id' => $subscriptionPlan->id,
                    ];
                }

                $tenant->modules()->sync($syncData);
            }

            // Bootstrap RBAC (Owner/Admin roles + base permissions) and assign Owner role to this user
            \App\Jobs\BootstrapTenantRbac::dispatchSync($user->id);

            $user->sendEmailVerificationNotification();
            $user->notify(new TenantCreated($tenant, Auth::user()));
            Auth::user()->notify(new TenantCreated($tenant, Auth::user()));

            tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));

            return response()->json([
                'message' => 'Tenant and owner created successfully!',
                'tenant_id' => $tenant->id,
                'domain' => "{$request->input('domain')}.".env('CENTRAL_DOMAIN'),
                'email' => $tenant->email,
                'name' => $tenant->name,
                'owner' => $user->name,
                'password' => $request->input('password'),
                'subscription_plan' => $subscriptionPlan->name,
                'subscription_plan_id' => $subscriptionPlan->id,
                'subscription_status' => $tenant->subscription_status,
                'subscription_start_date' => $tenant->subscription_start_date,
                'subscription_end_date' => $tenant->subscription_end_date,
                'auto_renew' => $tenant->auto_renew,
                'trial_ends_at' => $tenant->subscription_end_date->format('Y-m-d'),
                'assigned_modules' => $tenant->modules()->get()->map(function($m){
                    return [
                        'id' => $m->id,
                        'name' => $m->name,
                        'assigned_price' => $m->pivot->assigned_price,
                        'is_included' => $m->pivot->is_included,
                    ];
                }),
                'calculated_total_price' => $tenant->calculateAssignedTotalPrice(),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to create tenant: '.$e->getMessage(),
            ], 500);
        }
    }

    public function deleteTenant($id)
    {
        if (! $this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        try {
            $tenant = Tenant::findOrFail($id);
            $tenant->delete();

            tenancy()->central(fn () => Cache::store('database')->forget('central_tenants_all'));

            return response()->json(['message' => 'Tenant deleted successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete tenant: '.$e->getMessage()], 500);
        }
    }

    public function bulkDeleteTenants(Request $request)
    {
        if (! $this->isAuthorized()) {
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
        if (! $this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        $cacheKey = "central_tenant_show_{$id}";

        $tenant = tenancy()->central(fn () => Cache::store('database')->get($cacheKey));

        if (! $tenant) {
            $model = Tenant::with(['domains', 'subscriptionPlan'])->find($id);
            if (! $model) {
                return response()->json(['message' => 'Tenant not found'], 404);
            }

            tenancy()->initialize($model);
            $owner = User::first(); // Note: Owner identification now via roles table

            $tenant = [
                'id' => $model->id,
                'name' => $model->name,
                'email' => $model->email,
                'domain' => optional($model->domains->first())->domain,
                'owner' => optional($owner)->name,
                'created_at' => $model->created_at ? $model->created_at->toDateTimeString() : null,
                'updated_at' => $model->updated_at ? $model->updated_at->toDateTimeString() : null,
                // Subscription fields
                'subscription_plan_id' => $model->subscription_plan_id,
                'subscription_plan_name' => $model->subscriptionPlan?->name,
                'subscription_plan_code' => $model->subscriptionPlan?->code,
                'subscription_start_date' => $model->subscription_start_date,
                'subscription_end_date' => $model->subscription_end_date,
                'subscription_status' => $model->subscription_status,
                'auto_renew' => $model->auto_renew,
                'last_billing_date' => $model->last_billing_date,
                'next_billing_date' => $model->next_billing_date,
            ];

            tenancy()->central(fn () => Cache::store('database')->forever($cacheKey, $tenant));
        }

        return response()->json($tenant);
    }

    public function updateTenant(UpdateTenantRequest $request, $id)
    {
        if (! $this->isAuthorized()) {
            return response()->json(['message' => 'Only owner or admins can perform this operation'], 403);
        }

        try {
            $tenant = Tenant::findOrFail($id);

            $tenant->update([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
            ]);

            if ($request->filled('domain')) {
                $tenant->domains()->update([
                    'domain' => "{$request->input('domain')}.".env('CENTRAL_DOMAIN'),
                ]);
            }

            if ($request->filled('password')) {
                $tenant->update([
                    'password' => Hash::make($request->input('password')),
                ]);
            }

            tenancy()->central(function () use ($id) {
                Cache::store('database')->forget('central_tenants_all');
                Cache::store('database')->forget("central_tenant_show_{$id}");
            });

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
            return response()->json(['error' => 'Failed to update tenant: '.$e->getMessage()], 500);
        }
    }

    public function exportExcell()
    {
        if (! $this->isAuthorized()) {
            return response()->json(['message' => 'Access denied. Only owner or admin can view tenants.'], 403);
        }

        $query = Tenant::query()
            ->leftJoin('domains', 'tenants.id', '=', 'domains.tenant_id')
            ->select(['tenants.id', 'tenants.name', 'tenants.email', 'domains.domain', 'tenants.created_at', 'tenants.updated_at']);

        if (! $query->exists()) {
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
            ->select(['tenants.id', 'tenants.name', 'tenants.email', 'domains.domain', 'tenants.created_at', 'tenants.updated_at',
                'created_at', 'updated_at'])
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

    // getting tenant by name
    public function getTenantByName($name)
    {

        $tenant = Tenant::with(['domains', 'subscriptionPlan'])->where('id', $name)->first();

        if (! $tenant) {
            return response()->json([
                'message' => "{$name} not found. Check the name and try again.",
            ], 404);
        }

        return response()->json([
            'message' => 'Tenant found',
            'tenant' => $name,
            'subscription_end_date' => $tenant->subscription_end_date,
        ]);
    }
}
