<?php

declare(strict_types=1);

namespace Hypervel\Permission\Models;

use Carbon\CarbonInterface;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\UniqueConstraintViolationException;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionRelationContext;
use Hypervel\Permission\Traits\HasPermissionPartition;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Permission\Traits\RefreshesPermissionCache;
use UnitEnum;

use function Hypervel\Support\enum_value;

/**
 * @property int|string $id
 * @property string $name
 * @property string $guard_name
 * @property null|CarbonInterface $created_at
 * @property null|CarbonInterface $updated_at
 * @property-read Collection<int, Role> $roles
 * @property-read Collection<int, Model> $users
 */
class Permission extends Model implements PermissionContract
{
    use HasPermissionPartition;
    use HasRoles;
    use RefreshesPermissionCache;

    protected array $guarded = [];

    public function __construct(array $attributes = [])
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);

        parent::__construct($attributes);

        $this->guarded[] = $this->primaryKey;
        $this->table = Config::permissionsTable() ?: parent::getTable();
    }

    /**
     * Get the permission's guard name.
     */
    public function guardName(): string
    {
        if (! array_key_exists('guard_name', $this->getAttributes())) {
            if ($this->exists) {
                throw new MissingAttributeException($this, 'guard_name');
            }

            return Guard::getDefaultName(static::class);
        }

        /** @var string $guardName */
        $guardName = $this->getAttribute('guard_name');

        return $guardName;
    }

    /**
     * @return Permission|PermissionContract
     *
     * @throws PermissionAlreadyExists
     */
    public static function create(array $attributes = []): PermissionContract
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);

        $attributes['name'] = enum_value($attributes['name']);

        $permission = static::findByParam(['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']]);

        if ($permission) {
            throw PermissionAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        $query = static::query();

        try {
            /** @var PermissionContract $permission */
            $permission = $query->withSavepointIfNeeded(fn () => $query->create($attributes));

            return $permission;
        } catch (UniqueConstraintViolationException) {
            throw PermissionAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }
    }

    /**
     * A permission can be applied to roles.
     */
    public function roles(): BelongsToMany
    {
        return $this->roleAssignmentRelation();
    }

    /**
     * Build the permission-role relation for a captured context.
     */
    protected function roleAssignmentRelation(
        ?PermissionRelationContext $context = null,
    ): BelongsToMany {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        return $this->permissionBelongsToMany(
            Config::roleModel(),
            Config::roleHasPermissionsTable(),
            $registrar->pivotPermission,
            $registrar->pivotRole,
            'roles',
            $context,
        )->withPivot('is_denied');
    }

    /**
     * A permission belongs to some users of the model associated with its guard.
     */
    public function users(): BelongsToMany
    {
        return $this->permissionMorphToMany(
            getModelForGuard($this->guardName()),
            Config::modelHasPermissionsTable(),
            Container::getInstance()->make(PermissionRegistrar::class)->pivotPermission,
            Config::morphKey(),
            'users',
            inverse: true,
            teamScoped: Config::teamsEnabled(),
        );
    }

    /**
     * Find a permission by its name (and optionally guardName).
     *
     * @return Permission|PermissionContract
     *
     * @throws PermissionDoesNotExist
     */
    public static function findByName(UnitEnum|string $name, ?string $guardName = null): PermissionContract
    {
        $name = enum_value($name);
        $guardName ??= Guard::getDefaultName(static::class);
        $permission = static::getPermission(['name' => $name, 'guard_name' => $guardName]);
        if (! $permission) {
            throw PermissionDoesNotExist::create($name, $guardName);
        }

        return $permission;
    }

    /**
     * Find a permission by its id (and optionally guardName).
     *
     * @return Permission|PermissionContract
     *
     * @throws PermissionDoesNotExist
     */
    public static function findById(int|string $id, ?string $guardName = null): PermissionContract
    {
        $guardName ??= Guard::getDefaultName(static::class);
        $permission = static::getPermission([Guard::getModelKeyName(static::class) => $id, 'guard_name' => $guardName]);

        if (! $permission) {
            throw PermissionDoesNotExist::withId($id, $guardName);
        }

        return $permission;
    }

    /**
     * Find or create permission by its name (and optionally guardName).
     *
     * @return Permission|PermissionContract
     */
    public static function findOrCreate(UnitEnum|string $name, ?string $guardName = null): PermissionContract
    {
        $name = enum_value($name);
        $guardName ??= Guard::getDefaultName(static::class);
        $permission = static::findByParam(['name' => $name, 'guard_name' => $guardName]);

        if (! $permission) {
            $attributes = ['name' => $name, 'guard_name' => $guardName];
            $query = static::query();

            if (static::isSoftDeletable()) {
                // @phpstan-ignore method.notFound (SoftDeletingScope adds this method)
                return $query->createOrRestore($attributes);
            }

            return $query->createOrFirst($attributes);
        }

        return $permission;
    }

    /**
     * Find a permission based on an array of parameters.
     *
     * @return null|Permission|PermissionContract
     */
    protected static function findByParam(array $params = []): ?PermissionContract
    {
        $query = static::query();

        foreach ($params as $key => $value) {
            $query->where($key, $value);
        }

        return $query->first();
    }

    /**
     * Get the current cached permissions.
     */
    protected static function getPermissions(array $params = [], bool $onlyOne = false): Collection
    {
        return Container::getInstance()->make(PermissionRegistrar::class)
            ->getPermissions($params, $onlyOne, static::class);
    }

    /**
     * Get the current cached first permission.
     *
     * @return null|Permission|PermissionContract
     */
    protected static function getPermission(array $params = []): ?PermissionContract
    {
        /** @var null|PermissionContract */
        return static::getPermissions($params, true)->first();
    }
}
