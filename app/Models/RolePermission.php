<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RolePermission extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'role_permissions';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'role_id',
        'permission_id',
        'can_view',
        'can_add',
        'can_edit',
        'can_delete',
        'can_import',
        'can_export',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'can_view' => 'boolean',
        'can_add' => 'boolean',
        'can_edit' => 'boolean',
        'can_delete' => 'boolean',
        'can_import' => 'boolean',
        'can_export' => 'boolean',
    ];

    /**
     * Get the role that owns this permission.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Get the permission that belongs to this role.
     */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }

    /**
     * Check if the role has any permissions for this resource.
     */
    public function hasAnyPermission(): bool
    {
        return $this->can_view || $this->can_add || $this->can_edit || $this->can_delete || $this->can_import || $this->can_export;
    }

    /**
     * Check if the role has all permissions for this resource.
     */
    public function hasAllPermissions(): bool
    {
        return $this->can_view && $this->can_add && $this->can_edit && $this->can_delete && $this->can_import && $this->can_export;
    }
}
