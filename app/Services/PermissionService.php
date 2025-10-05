<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class PermissionService
{
    /**
     * Check whether a user has a given permission action on a resource.
     */
    public function userHas(string $resourceKey, string $action, User $user): bool
    {
        $actionFlag = match ($action) {
            'view' => 'can_view',
            'add' => 'can_add',
            'edit' => 'can_edit',
            'delete' => 'can_delete',
            default => null,
        };

        if (! $actionFlag) {
            return false;
        }

        $tenantId = tenant('id');
        $cacheKey = "perm_{$tenantId}_user_{$user->id}_{$resourceKey}_{$actionFlag}";

        $cachedResult = app('cache')->store('database')->get($cacheKey);

        if ($cachedResult === null) {
            $result = DB::table('users')
                ->join('roles', 'users.role_id', '=', 'roles.id')
                ->join('role_permissions', 'roles.id', '=', 'role_permissions.role_id')
                ->join('permissions', 'role_permissions.permission_id', '=', 'permissions.id')
                ->where('users.id', $user->id)
                ->where('permissions.resource_key', $resourceKey)
                ->where("role_permissions.{$actionFlag}", true)
                ->exists();

            app('cache')->store('database')->put($cacheKey, $result, now()->addSeconds(60));

            return $result;
        }

        return $cachedResult;
    }
}
