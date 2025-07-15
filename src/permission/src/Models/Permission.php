<?php

declare(strict_types=1);

namespace Hypervel\Permission\Models;

use Carbon\Carbon;
use Hyperf\Database\Model\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Traits\HasRole;

/**
 * @property int $id
 * @property string $name
 * @property string $guard_name
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<Role> $roles
 */
class Permission extends Model implements PermissionContract
{
    use HasRole;

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'name',
        'guard_name',
    ];

    /**
     * A permission can be applied to roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            config('permission.models.role', Role::class),
            config('permission.table_names.role_has_permissions', 'role_has_permissions'),
            config('permission.column_names.permission_pivot_key', 'permission_id'),
            config('permission.column_names.role_pivot_key', 'role_id'),
        )
            ->withTimestamps()
            ->withPivot(['is_forbidden']);
    }
}
