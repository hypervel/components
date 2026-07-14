<?php

declare(strict_types=1);

namespace Hypervel\Permission\Models;

use Carbon\CarbonInterface;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\GuardDoesNotMatch;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Exceptions\RoleAlreadyExists;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Permission\Traits\HasAssignedModels;
use Hypervel\Permission\Traits\HasPermissionPartition;
use Hypervel\Permission\Traits\HasPermissions;
use Hypervel\Permission\Traits\RefreshesPermissionCache;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 * @property-read Collection<int, Permission> $permissions
 * @property-read Collection<int, Model> $users
 */
class Role extends Model implements RoleContract
{
    use HasAssignedModels;
    use HasPermissionPartition;
    use HasPermissions;
    use RefreshesPermissionCache;

    protected array $guarded = [];

    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);

        parent::__construct($attributes);

        $this->guarded[] = $this->primaryKey;
        $this->table = Config::rolesTable() ?: parent::getTable();
    }

    /**
     * @return Role|RoleContract
     *
     * @throws RoleAlreadyExists
     */
    public static function create(array $attributes = []): RoleContract
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);
        $attributes['name'] = enum_value($attributes['name']);

        $params = ['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']];

        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        if ($registrar->teams) {
            $teamsKey = $registrar->teamsKey;

            if (array_key_exists($teamsKey, $attributes)) {
                $params[$teamsKey] = $attributes[$teamsKey];
            } else {
                $attributes[$teamsKey] = getPermissionsTeamId();
            }
        }

        if (static::findByParam($params)) {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        $query = static::query();

        try {
            /** @var RoleContract $role */
            $role = $query->withSavepointIfNeeded(fn () => $query->create($attributes));

            return $role;
        } catch (UniqueConstraintViolationException) {
            throw RoleAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }
    }

    /**
     * A role may be given various permissions.
     */
    public function permissions(): BelongsToMany
    {
        return $this->permissionAssignmentRelation();
    }

    /**
     * Build the role-permission relation for a captured context.
     */
    protected function permissionAssignmentRelation(
        ?PermissionRelationContext $context = null,
    ): BelongsToMany {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        return $this->permissionBelongsToMany(
            Config::permissionModel(),
            Config::roleHasPermissionsTable(),
            $registrar->pivotRole,
            $registrar->pivotPermission,
            'permissions',
            $context,
        )->withPivot('is_forbidden');
    }

    /**
     * A role belongs to some users of the model associated with its guard.
     */
    public function users(): BelongsToMany
    {
        return $this->permissionMorphToMany(
            getModelForGuard($this->attributes['guard_name'] ?? Config::defaultGuard()),
            Config::modelHasRolesTable(),
            Container::getInstance()->make(PermissionRegistrar::class)->pivotRole,
            Config::morphKey(),
            'users',
            inverse: true,
            teamScoped: Config::teamsEnabled(),
        );
    }

    /**
     * Find a role by its name and guard name.
     *
     * @return Role|RoleContract
     *
     * @throws RoleDoesNotExist
     */
    public static function findByName(UnitEnum|string $name, ?string $guardName = null): RoleContract
    {
        $name = enum_value($name);
        $guardName ??= Guard::getDefaultName(static::class);

        $role = static::getRole(['name' => $name, 'guard_name' => $guardName]);

        if (! $role) {
            throw RoleDoesNotExist::named($name, $guardName);
        }

        return $role;
    }

    /**
     * Find a role by its id (and optionally guardName).
     *
     * @return Role|RoleContract
     */
    public static function findById(int|string $id, ?string $guardName = null): RoleContract
    {
        $guardName ??= Guard::getDefaultName(static::class);

        $role = static::getRole([Guard::getModelKeyName(static::class) => $id, 'guard_name' => $guardName]);

        if (! $role) {
            throw RoleDoesNotExist::withId($id, $guardName);
        }

        return $role;
    }

    /**
     * Find or create role by its name (and optionally guardName).
     *
     * @return Role|RoleContract
     */
    public static function findOrCreate(UnitEnum|string $name, ?string $guardName = null): RoleContract
    {
        $name = enum_value($name);
        $guardName ??= Guard::getDefaultName(static::class);

        $attributes = ['name' => $name, 'guard_name' => $guardName];

        $role = static::findByParam($attributes);

        if (! $role) {
            $registrar = Container::getInstance()->make(PermissionRegistrar::class);
            if ($registrar->teams) {
                $teamsKey = $registrar->teamsKey;
                $attributes[$teamsKey] = getPermissionsTeamId();
            }

            $query = static::query();

            if (static::isSoftDeletable()) {
                // @phpstan-ignore method.notFound (SoftDeletingScope adds this method)
                return $query->createOrRestore($attributes);
            }

            return $query->createOrFirst($attributes);
        }

        return $role;
    }

    /**
     * Finds a role based on an array of parameters.
     *
     * @return null|Role|RoleContract
     */
    protected static function findByParam(array $params = []): ?RoleContract
    {
        $query = static::query();

        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        if ($registrar->teams) {
            $teamsKey = $registrar->teamsKey;

            $query->where(
                fn ($q) => $q->whereNull($teamsKey)
                    ->orWhere($teamsKey, $params[$teamsKey] ?? getPermissionsTeamId())
            );
            unset($params[$teamsKey]);
        }

        foreach ($params as $key => $value) {
            $query->where($key, $value);
        }

        return $query->first();
    }

    /**
     * Get the current cached roles.
     */
    protected static function getRoles(array $params = [], bool $onlyOne = false): Collection
    {
        return Container::getInstance()->make(PermissionRegistrar::class)
            ->getRoles($params, $onlyOne, static::class);
    }

    /**
     * Get the current cached first role.
     *
     * @return null|Role|RoleContract
     */
    protected static function getRole(array $params = []): ?RoleContract
    {
        /** @var null|RoleContract */
        return static::getRoles($params, true)->first();
    }

    /**
     * Determine if the role may perform the given permission.
     *
     * @throws GuardDoesNotMatch|PermissionDoesNotExist
     */
    public function hasPermissionTo(UnitEnum|int|string|PermissionContract $permission, ?string $guardName = null): bool
    {
        if ($this->getWildcardClass()) {
            if ($this->hasForbiddenPermission($permission, $guardName)) {
                return false;
            }

            return $this->hasWildcardPermission($permission, $guardName);
        }

        $permission = $this->filterPermission($permission, $guardName);

        if ($this->hasForbiddenPermission($permission, $guardName)) {
            return false;
        }

        if (! $this->getGuardNames()->contains($permission->guard_name)) {
            throw GuardDoesNotMatch::create($permission->guard_name, $guardName ? collect([$guardName]) : $this->getGuardNames());
        }

        $matches = $this->relationCollection($this, 'permissions')
            ->filter(fn (Model $rolePermission): bool => $rolePermission->getKey() === $permission->getKey());

        return $matches->isNotEmpty()
            && ! $matches->contains(fn (Model $rolePermission): bool => $this->pivotIsForbidden($rolePermission));
    }
}
