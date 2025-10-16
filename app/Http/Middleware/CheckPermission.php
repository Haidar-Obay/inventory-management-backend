<?php

namespace App\Http\Middleware;

use Illuminate\Support\Facades\DB;
use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function __construct(private PermissionService $permissionService) {}

    public function handle(Request $request, Closure $next, ?string $resourceKey = null, ?string $action = null)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Infer action from method if not provided
        if (! $action) {
            $method = strtoupper($request->method());
            $action = match ($method) {
                'GET' => 'view',
                'POST' => 'add',
                'PUT', 'PATCH' => 'edit',
                'DELETE' => 'delete',
                default => null,
            };
        }

        if (! $resourceKey || ! $action) {
            return response()->json(['message' => 'Forbidden. Missing permission metadata.'], 403);
        }

        // Module-resource gate: ensure this tenant has a module that exposes this backend resource
        $tenantId = tenant('id');
        // Check centrally: modules/module_resources/tenant_modules live in central DB
        $central = config('tenancy.database.central_connection', config('database.default'));
        $resourceAvailable = DB::connection($central)
            ->table('module_resources')
            ->join('modules', 'module_resources.module_id', '=', 'modules.id')
            ->join('tenant_modules', 'modules.id', '=', 'tenant_modules.module_id')
            ->join('tenants', 'tenant_modules.tenant_id', '=', 'tenants.id')
            ->where('module_resources.code', $resourceKey)
            ->where('tenants.id', $tenantId)
            ->exists();

        if (! $resourceAvailable) {
            logger()->warning('Module resource denied', [
                'tenant_id' => $tenantId,
                'user_id' => $user->id,
                'resource_key' => $resourceKey,
                'action' => $action,
                'route' => $request->path(),
            ]);

            return response()->json(['message' => 'Forbidden. Resource not available for tenant.'], 403);
        }

        $allowed = $this->permissionService->userHas($resourceKey, $action, $user);

        if (! $allowed) {
            // Optional: basic logging
            logger()->warning('Permission denied', [
                'tenant_id' => tenant('id'),
                'user_id' => $user->id,
                'resource_key' => $resourceKey,
                'action' => $action,
                'route' => $request->path(),
            ]);

            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
