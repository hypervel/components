<?php

declare(strict_types=1);

namespace Hypervel\Permission\Models;

use Carbon\CarbonInterface;
use Hypervel\Container\Container;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\Guard;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
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
     * @return Permission|PermissionContract
     *
     * @throws PermissionAlreadyExists
     */
    public static function create(array $attributes = []): PermissionContract
    {
        $attributes['guard_name'] ??= Guard::getDefaultName(static::class);

        $attributes['name'] = enum_value($attributes['name']);

        $permission = static::getPermission(['name' => $attributes['name'], 'guard_name' => $attributes['guard_name']]);

        if ($permission) {
            throw PermissionAlreadyExists::create($attributes['name'], $attributes['guard_name']);
        }

        return static::query()->create($attributes);
    }

    /**
     * A permission can be applied to roles.
     */
    public function roles(): BelongsToMany
    {
        $registrar = Container::getInstance()->make(PermissionRegistrar::class);

        return $this->belongsToMany(
            Config::roleModel(),
            Config::roleHasPermissionsTable(),
            $registrar->pivotPermission,
            $registrar->pivotRole
        )->withPivot('is_forbidden');
    }

    /**
     * A permission belongs to some users of the model associated with its guard.
     */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(
            getModelForGuard($this->attributes['guard_name'] ?? Config::defaultGuard()),
            'model',
            Config::modelHasPermissionsTable(),
            Container::getInstance()->make(PermissionRegistrar::class)->pivotPermission,
            Config::morphKey()
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
        $permission = static::getPermission(['name' => $name, 'guard_name' => $guardName]);

        if (! $permission) {
            return static::query()->create(['name' => $name, 'guard_name' => $guardName]);
        }

        return $permission;
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
