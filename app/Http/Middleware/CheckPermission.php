<?php

namespace App\Http\Middleware;

use App\Services\PermissionService;
use Closure;
use Illuminate\Http\Request;

class CheckPermission
{
    public function __construct(private PermissionService $permissionService)
    {
    }

    public function handle(Request $request, Closure $next, string $resourceKey = null, string $action = null)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Infer action from method if not provided
        if (!$action) {
            $method = strtoupper($request->method());
            $action = match ($method) {
                'GET' => 'view',
                'POST' => 'add',
                'PUT', 'PATCH' => 'edit',
                'DELETE' => 'delete',
                default => null,
            };
        }

        if (!$resourceKey || !$action) {
            return response()->json(['message' => 'Forbidden. Missing permission metadata.'], 403);
        }

        $allowed = $this->permissionService->userHas($resourceKey, $action, $user);

        if (!$allowed) {
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
