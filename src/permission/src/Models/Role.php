<?php

declare(strict_types=1);

namespace Hypervel\Permission\Models;

use Carbon\Carbon;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Traits\HasPermission;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<Permission> $permissions
 */
class Role extends Model implements RoleContract
{
    use HasPermission;

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'name',
        'guard_name',
    ];

    /**
     * A role may be given various permissions.
     */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            config('permission.models.permission', Permission::class),
            config('permission.table_names.role_has_permissions', 'role_has_permissions'),
            config('permission.column_names.role_pivot_key', 'role_id'),
            config('permission.column_names.permission_pivot_key', 'permission_id'),
        )
            ->withTimestamps()
            ->withPivot(['is_forbidden']);
    }
}
