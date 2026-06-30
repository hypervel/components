# Fresh Port Plan: `hypervel/permission` From `spatie/laravel-permission`

## Objective

Fresh-port the current `spatie/laravel-permission` package into `src/permission` for Hypervel 0.4, while preserving the useful Hypervel-specific improvements from the existing package:

- Forbidden permissions, where a denied permission overrides direct and role-granted allows.
- Hot permission-check caching tuned for long-lived Swoole workers.
- Coroutine-safe request/team state.
- Strict PHP 8.4+ types, docblocks, and PHPStan-clean code.

The current Hypervel permission package was originally built for the older Hypervel 0.3 / Hyperf-based line and then carried forward. It is missing large parts of the current Spatie package. This work should be treated as a fresh package port, not a patch against the existing implementation.

Before implementation starts, move the current package body into an archive location and leave only the empty package skeleton in place:

```text
_archive/
└── permission/
    └── ...current package files...

src/permission/
├── LICENSE.md
├── README.md
└── composer.json
```

The archive is reference material only. New source and tests should be copied from upstream Spatie one file at a time, then adapted for Hypervel.

## Source Inputs

Use these sources:

- Upstream Spatie package: `/tmp/spatie-laravel-permission`
- Current Hypervel package: `src/permission`
- Current Hypervel tests: `tests/Permission`
- Current Hypervel docs: `src/boost/docs/permission.md`
- Upstream Spatie docs: `/tmp/spatie-laravel-permission/docs`
- Upstream Spatie Boost skill: `/tmp/spatie-laravel-permission/resources/boost/skills/laravel-permission-development/SKILL.md`
- Cache memo / stack behavior: `src/cache/src/CacheManager.php`, `src/cache/src/MemoizedStore.php`, `src/cache/src/StackStore.php`, `tests/Cache/CacheMemoizedStoreTest.php`
- Global test cleanup registry: `tests/AfterEachTestSubscriber.php`

The upstream package should be current before porting:

```sh
git -C /tmp/spatie-laravel-permission pull --ff-only
```

The current upstream state inspected for this plan was commit `c2c871a` with tags through `8.0.0`.

## Main Decisions

1. Fresh port, not incremental repair.

   The existing Hypervel package is much smaller than upstream Spatie. A fresh port is safer and clearer because it avoids preserving old 0.3-era structure by accident.

2. Use Spatie / Laravel naming for schema and APIs.

   Use `model_has_roles`, `model_has_permissions`, `model_morph_key`, and the morph relation name `model`. The existing `owner_*` naming is a bad divergence: it makes docs, upstream tests, future merges, and AI-assisted maintenance harder without a real runtime benefit.

3. Use Spatie plural traits as canonical.

   Implement `HasRoles` and `HasPermissions`. Do not create singular trait aliases named `HasRole` or `HasPermission` in the fresh port. The docs should teach only the Spatie-style plural traits.

4. Port Passport client-credentials support.

   Hypervel Passport will be added soon. The permission package should already include Spatie's Passport client path so routes protected by role/permission middleware can authorize machine-to-machine clients once Passport lands.

5. Do not port Laravel Octane support as behavior.

   Hypervel is Swoole-native, not Laravel running under Octane. Team/request state must live in `CoroutineContext`; cache freshness is handled by configured cache stores, per-coroutine memo, stack cache, and explicit invalidation. Porting `register_octane_reset_listener` would add a Laravel-specific mechanism that does not belong in Hypervel.

6. Port teams, including the teams setup command.

   Teams are a supported Spatie feature and should be available in Hypervel. The base published migration should support teams when teams are enabled before the initial migration, and `permission:setup-teams` should be ported so an app can enable teams after its first install by publishing the teams upgrade migration stub.

7. Preserve forbidden permissions.

   This is a real Hypervel improvement. It should be integrated into the Spatie API shape rather than kept as a separate old implementation.

8. Use configured cache plus `CacheManager::memo()`.

   Permission data should be backed by the configured cache store so cache invalidation works across workers and nodes. Wrap hot reads in the cache manager's memoized repository so repeated checks inside one coroutine do not repeatedly hit Redis / database / file cache. This is better than `once()` because it composes with the cache repository, cache invalidation, and stack stores.

9. Cache stable metadata in worker memory only when safe.

   Config-derived class names, table names, pivot keys, and booleans can be cached on the registrar for the worker lifetime because config is process-global after boot. Mutable permission assignment data must not be stored only in raw static properties because it would become stale across workers and deployments.

10. Events should be dormant until useful.

    Keep Spatie's `events_enabled` config, and additionally avoid constructing / dispatching event objects unless the dispatcher has listeners for that event class. This matches Hypervel's event-cost policy.

## Upstream Surface To Port

Port these upstream source files, preserving upstream order inside classes and adapting namespaces/types:

```text
Commands/AssignRoleCommand.php
Commands/CacheResetCommand.php
Commands/CreatePermissionCommand.php
Commands/CreateRoleCommand.php
Commands/ShowCommand.php
Commands/UpgradeForTeamsCommand.php
Contracts/Permission.php
Contracts/PermissionsTeamResolver.php
Contracts/Role.php
Contracts/Wildcard.php
DefaultTeamResolver.php
Events/PermissionAttachedEvent.php
Events/PermissionDetachedEvent.php
Events/RoleAttachedEvent.php
Events/RoleDetachedEvent.php
Exceptions/GuardDoesNotMatch.php
Exceptions/PermissionAlreadyExists.php
Exceptions/PermissionDoesNotExist.php
Exceptions/RoleAlreadyExists.php
Exceptions/RoleDoesNotExist.php
Exceptions/TeamModelNotConfigured.php
Exceptions/TeamsNotEnabled.php
Exceptions/UnauthorizedException.php
Exceptions/WildcardPermissionInvalidArgument.php
Exceptions/WildcardPermissionNotImplementsContract.php
Exceptions/WildcardPermissionNotProperlyFormatted.php
Guard.php
Middleware/PermissionMiddleware.php
Middleware/RoleMiddleware.php
Middleware/RoleOrPermissionMiddleware.php
Models/Permission.php
Models/Role.php
PermissionRegistrar.php
PermissionServiceProvider.php
Support/Config.php
Traits/HasAssignedModels.php
Traits/HasPermissions.php
Traits/HasRoles.php
Traits/RefreshesPermissionCache.php
WildcardPermission.php
helpers.php
```

Also port and adapt these upstream package files:

```text
config/permission.php
database/migrations/create_permission_tables.php.stub
database/migrations/add_teams_fields.php.stub
```

## Current Hypervel Improvements To Preserve

### Forbidden Permissions

Preserve the current `is_forbidden` concept:

- Add `is_forbidden` to `role_has_permissions`.
- Add `is_forbidden` to `model_has_permissions`.
- `giveForbiddenTo(...)` attaches permissions with `is_forbidden = true`.
- `hasForbiddenPermission(...)` checks direct forbidden permissions.
- `hasForbiddenPermissionViaRoles(...)` checks forbidden permissions inherited through assigned roles.
- `hasPermissionTo(...)`, `checkPermissionTo(...)`, Gate checks, and middleware checks must return false when a forbidden permission applies.
- If a permission is both allowed and forbidden, forbidden wins.
- `getAllPermissions()` and `getPermissionsViaRoles()` should exclude forbidden permissions from the allowed result sets.

Use Spatie's canonical methods as the public surface:

- `hasPermissionTo(...)`
- `hasAnyPermission(...)`
- `hasAllPermissions(...)`
- `hasDirectPermission(...)`
- `hasAnyDirectPermission(...)`
- `hasRole(...)`
- `hasAnyRole(...)`
- `hasAllRoles(...)`

Do not keep the old Hypervel singular trait names or old convenience aliases such as `hasPermission(...)`, `hasAnyPermissions(...)`, `hasAnyRoles(...)`, or `hasPermissionViaRoles(...)`. Hypervel 0.4 is greenfield, and preserving old 0.3-era aliases would make the fresh port less Spatie-compatible without improving the new package.

### Unit Enum Inputs

Preserve Hypervel 0.3's unit-enum support. Spatie's package only types many public inputs as `BackedEnum|string`, but Hypervel's `enum_value()` also supports pure `UnitEnum` by using the case name. Widen native contracts and model signatures to `UnitEnum` where enum names are valid inputs so pure unit enums are not rejected before the method body runs.

### Owner / Model Assignment Caching

Keep the useful idea from the current `PermissionManager`: direct model roles and direct model permissions are hot paths and should not query the database repeatedly during one request/job.

Implement this through the new `PermissionRegistrar`:

- Global permission/role cache stores current role and permission records needed to hydrate checks, extended to include `is_forbidden` pivot data.
- Model direct-role cache stores assignment identifiers and pivot flags, not full role records.
- Model direct-permission cache stores assignment identifiers and pivot flags, not full permission records.
- Cache keys include morph class, model key, and active team id when teams are enabled.
- Cache keys include a registrar assignment-cache version so global role/permission saves, deletes, and cache resets cannot leave old model assignment caches pointing at stale role or permission records.
- Cache keys use Spatie naming (`model`, not `owner`).
- Affected model cache entries are forgotten when direct role/permission assignments change.
- Role/permission model saves and deletes clear the global cached catalog and bump the assignment-cache version.
- Role/permission model saves and deletes clear global cached permissions through `RefreshesPermissionCache`.

Example key shape:

```php
public const MODEL_ROLES_CACHE_KEY_PREFIX = 'hypervel.permission.cache.model.roles';
public const MODEL_PERMISSIONS_CACHE_KEY_PREFIX = 'hypervel.permission.cache.model.permissions';
public const MODEL_CACHE_VERSION_KEY = 'hypervel.permission.cache.model.version';

protected function modelCacheKey(string $prefix, Model $model): string
{
    $teamId = $this->teams ? (string) ($this->getPermissionsTeamId() ?? 'global') : 'none';
    $version = $this->assignmentCacheVersion();

    return "{$prefix}:{$version}:{$model->getMorphClass()}:{$model->getKey()}:{$teamId}";
}
```

Use `$model->getMorphClass()` because it matches the persisted `model_type` value and honors morph maps.

## Hypervel Adaptations

### Namespaces And File Conventions

Convert:

- `Spatie\Permission\...` to `Hypervel\Permission\...`
- `Illuminate\...` to the matching `Hypervel\...` namespace
- `Illuminate\Support\enum_value` to `Hypervel\Support\enum_value`
- Laravel facades/helpers in class code to injected dependencies or container-resolved services where constructor injection is not practical

Every PHP file needs:

```php
<?php

declare(strict_types=1);
```

Add Laravel-style title docblocks to methods. Do not add class docblocks unless a class has a real non-obvious role that needs explaining.

`Support\Config` should be the central config access layer for package internals. Use typed methods on Hypervel's config repository inside that class, resolved through the container because the upstream API is static. Other package classes should call `Support\Config` or registrar accessors rather than scattering raw `config()` calls through class code.

### Service Provider

Use `Hypervel\Support\ServiceProvider`, not Spatie's package-tools provider.

Register:

- config merge
- publishing for config and migration
- commands
- model contract bindings
- route macros
- Blade directives
- Gate `before` permission hook
- middleware aliases
- `AboutCommand` information
- `PermissionRegistrar` singleton

Use container `make()` directly, not array access.

Example:

```php
public function register(): void
{
    $this->mergeConfigFrom(__DIR__ . '/../config/permission.php', 'permission');

    $this->app->singleton(PermissionRegistrar::class, fn ($app) => new PermissionRegistrar(
        $app->make(CacheManager::class),
        $app->make('config'),
        $app,
    ));

    $this->app->bind(PermissionContract::class, fn ($app) => $app->make(
        $app->make('config')->string('permission.models.permission')
    ));

    $this->app->bind(RoleContract::class, fn ($app) => $app->make(
        $app->make('config')->string('permission.models.role')
    ));
}
```

Register middleware aliases at boot:

```php
$router = $this->app->make('router');

$router->aliasMiddleware('role', RoleMiddleware::class);
$router->aliasMiddleware('permission', PermissionMiddleware::class);
$router->aliasMiddleware('role_or_permission', RoleOrPermissionMiddleware::class);
```

Register `AboutCommand` under `Hypervel Permissions`, not `Spatie Permissions`. Do not include Octane in the feature list. Include:

- Teams
- Wildcard Permissions
- Passport Client Credentials
- Forbidden Permissions

### Intentional Difference Comments

At the spot where an Octane listener would otherwise be ported, include:

```php
// Laravel Octane reset listeners are not ported. Hypervel stores transient team
// state in CoroutineContext and keeps permission cache freshness in the cache layer.
```

This comment is justified because it records a whole intentionally omitted upstream feature at the exact future-porting point.

### Config

Use Spatie's config shape with Hypervel namespaces and the Hypervel additions.

Required keys:

```php
return [
    'models' => [
        'permission' => \Hypervel\Permission\Models\Permission::class,
        'role' => \Hypervel\Permission\Models\Role::class,
        'team' => null,
        'default_model' => null,
    ],

    'storage' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    'register_permission_check_method' => true,
    'events_enabled' => false,
    'teams' => false,
    'team_resolver' => \Hypervel\Permission\DefaultTeamResolver::class,
    'use_passport_client_credentials' => false,
    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,
    'enable_wildcard_permission' => false,
    'wildcard_permission' => \Hypervel\Permission\WildcardPermission::class,

    'cache' => [
        'expiration_seconds' => 86400,
        'store' => 'default',
        'keys' => [
            'roles' => 'hypervel.permission.cache.roles',
            'model_roles' => 'hypervel.permission.cache.model.roles',
            'model_permissions' => 'hypervel.permission.cache.model.permissions',
            'model_version' => 'hypervel.permission.cache.model.version',
        ],
        'column_names_except' => ['created_at', 'updated_at', 'deleted_at'],
    ],
];
```

Use typed config access inside package code. Do not mutate config at runtime.

### Migrations

Create the greenfield base migration for Hypervel 0.4 and port the teams upgrade migration stub. The base migration should read config table and column names at runtime and create the final schema directly when teams are already enabled. The teams setup command should publish or create the upgrade migration stub for apps that enable teams after the base migration has already run.

Changes from Spatie:

- Use Hypervel namespaces.
- Include `is_forbidden` columns.
- Keep teams support in the primary migration and also ship the teams upgrade stub for later opt-in.
- Use `timestamp`, not `timestampTz`.
- Include a real `down()` method on the base create migration, matching Spatie and the existing Hypervel migration.
- Use configured connection.
- Keep custom primary key support by using configured pivot key names and model key config.
- Preserve Spatie's pivot foreign keys with `cascadeOnDelete()` on all three pivot tables.
- Preserve Spatie's `(name, guard_name)` unique constraints for roles and permissions when teams are disabled, and `(team_foreign_key, name, guard_name)` for roles when teams are enabled.

The schema should create:

```text
permissions
roles
model_has_permissions
model_has_roles
role_has_permissions
```

When teams are enabled:

- `roles` has nullable team foreign key.
- `model_has_permissions` primary key includes team foreign key.
- `model_has_roles` primary key includes team foreign key.
- role unique constraints include team foreign key.
- pivot foreign keys still cascade on delete.

Add `is_forbidden`:

```php
$table->boolean('is_forbidden')->default(false);
```

Use default false because this is a security flag and because old Hypervel semantics treat normal grants as allowed unless explicitly forbidden.

Include `is_forbidden` in the primary keys for `model_has_permissions` and `role_has_permissions`. This allows an allow row and a forbidden row to coexist for the same permission tuple so forbidden permissions can override allows.

### Permission Registrar

Port Spatie's `PermissionRegistrar` as the central service, replacing the current `PermissionManager`.

Keep the public class name `PermissionRegistrar` to match Spatie. Remove the current Hypervel-specific `Contracts\Factory` surface from the fresh port and bind `PermissionRegistrar` directly. Bind Spatie-compatible `Contracts\Permission` and `Contracts\Role` to the configured model classes.

The registrar owns:

- configured model classes
- configured team resolver
- configured cache repository
- cache key names
- pivot key names
- teams flag and team key
- Gate registration
- cache clearing
- cache hydration

Avoid Spatie's process-global permission collection as a stale source of truth. Use the configured cache store plus memoized repository for read paths:

```php
protected function cacheRepository(): Repository
{
    $store = $this->cacheStoreName === 'default' ? null : $this->cacheStoreName;

    return $this->cacheManager->memo($store);
}
```

The first read in a coroutine goes to the configured cache store. Later reads in the same coroutine come from the memo layer. Cross-worker freshness still depends on the configured cache backend and explicit invalidation.

When serializing the global permission cache, use a simple explicit payload instead of Spatie's alias-compressed payload. Include:

- permission attributes
- role relation
- role attributes
- `is_forbidden` pivot data for role-permission rows

Honor `permission.cache.column_names_except` when building the explicit payload so timestamp and soft-delete columns can be stripped without keeping Spatie's alias-compression complexity.

When hydrating, restore Eloquent model instances using `setRawAttributes()` and set the `roles` relation. Do not fire retrieved events for cache hydration.

Do not port Spatie's `isLoadingPermissions` retry loop. That loop is a Laravel-process guard around in-memory cache hydration. Hypervel should use the configured cache repository plus the per-coroutine memo layer instead of adding a shared loading flag to the singleton registrar. This keeps the read path simpler and avoids cross-coroutine coordination state.

### Worker-Lifetime Mutators

Methods such as `setPermissionClass()`, `setRoleClass()`, `setTeamClass()`, and `initializeCache()` mutate singleton-held state. Keep them because Spatie exposes them and tests/custom boot flows use them, but treat them as boot/test configuration methods rather than normal runtime APIs.

Do not call `Config::set()` or `config()->set()` inside these methods.

They should:

- update registrar-held class/config values
- update contract bindings
- clear relevant package caches
- have warning docblocks

Normal read paths must not call these setters to look up custom models. Query helpers should accept the intended model class directly where needed so one coroutine cannot temporarily change a singleton-held class for another coroutine.

Example:

```php
/**
 * Set the permission model class.
 *
 * Boot or tests only. The model class is stored on the singleton registrar
 * and affects every later permission lookup in this worker.
 */
public function setPermissionClass(string $permissionClass): static
{
    $this->permissionClass = $permissionClass;
    $this->app->bind(PermissionContract::class, $permissionClass);
    $this->forgetCachedPermissions();

    return $this;
}
```

### Teams And Coroutine Context

Spatie's `DefaultTeamResolver` stores `$teamId` on an object property. That leaks between concurrent requests in Hypervel because the resolver is owned by a singleton registrar.

Use `CoroutineContext`.

```php
final class DefaultTeamResolver implements PermissionsTeamResolver
{
    public const TEAM_ID_CONTEXT_KEY = '__permission.team_id';

    public function setPermissionsTeamId(int|string|Model|null $id): void
    {
        CoroutineContext::set(
            self::TEAM_ID_CONTEXT_KEY,
            $id instanceof Model ? $id->getKey() : $id,
        );
    }

    public function getPermissionsTeamId(): int|string|null
    {
        return CoroutineContext::get(self::TEAM_ID_CONTEXT_KEY);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        CoroutineContext::forget(self::TEAM_ID_CONTEXT_KEY);
    }
}
```

The context key is public so tests and nearby package code can assert against it.

Add `DefaultTeamResolver::flushState()` and `PermissionRegistrar::flushState()` to `tests/AfterEachTestSubscriber.php`. Do not add these to Testbench's application flush list; that list is for testbench app bootstrap state, not global framework cleanup.

`DefaultTeamResolver::flushState()` only clears the current coroutine/main context key. Normal test methods already run in fresh coroutines, so team state set inside a test method is naturally isolated. Tests that need a team id during setup should seed it inside `setUpInCoroutine()` or the test method itself, not in normal PHPUnit `setUp()`.

`PermissionRegistrar::flushState()` is tests-only cleanup that forgets the registrar singleton from the container and clears any static/default registrar state added during implementation. It does not replace per-test setup: any test that changes permission config must apply config first, then forget or reconstruct the registrar in `setUp()` so the singleton reads that test's config.

### Guards And Passport

Port `Guard` with Hypervel auth/config types:

- `getNames()`
- `getProviderModel()`
- `getConfigAuthGuards()`
- `getModelForGuard()`
- `getDefaultName()`
- `getPassportClient()`

`getPassportClient()` must be included even before Hypervel Passport lands. It should use generic behavior:

- scan configured auth guards where `driver === 'passport'`
- resolve that guard through auth manager
- if the guard has a `client()` method, call it
- return the client when no guard is requested, or when the client guard names include the requested guard

Tests should use a local fake Passport guard and fake client model implementing `Authorizable`. Do not require a real Passport package in the permission test suite.

### Models

Port `Models\Role` and `Models\Permission` from Spatie, with Hypervel additions.

Required model behavior:

- Config-derived table names in `__construct()`.
- Default guard name when creating.
- `findByName()`.
- `findById()`.
- `findOrCreate()`.
- duplicate create exceptions.
- custom primary key support.
- UUID / ULID ID lookup via `PermissionRegistrar::isUid()`.
- `RefreshesPermissionCache`.
- role/permission relations use configured pivot keys.
- user/model inverse relations use `model` morph naming.
- teams support on role creation and queries.
- forbidden permission support in relations and checks.

Use `CarbonInterface` or `CarbonImmutable` annotations where needed, not mutable `Carbon`.

### Traits

Port Spatie traits in this order:

```text
RefreshesPermissionCache
HasAssignedModels
HasPermissions
HasRoles
```

Then merge Hypervel forbidden-permission behavior into `HasPermissions`.

Important points:

- `HasRoles` uses `HasPermissions`.
- `Role` uses `HasAssignedModels`, `HasPermissions`, and `RefreshesPermissionCache`.
- `Permission` uses `HasRoles` and `RefreshesPermissionCache`.
- `HasPermissions::permissions()` must include `withPivot('is_forbidden')`.
- `Role::permissions()` and `Permission::roles()` must include `withPivot('is_forbidden')`.
- team pivot behavior must match Spatie.
- `teams()` relation should return a harmless empty relation when teams are disabled, matching upstream v7.4.1+.
- `scopeRole`, `scopeWithoutRole`, `scopePermission`, `scopeWithoutPermission`, `scopeTeam`, and `scopeWithoutTeam` must be ported.
- string `'0'` must not be treated as empty.
- guard mismatch checks must be kept.
- `Model::preventLazyLoading()` tests should pass.
- `Permission::getPermissions()` and `Role::getRoles()` must not mutate singleton registrar model classes as part of normal lookup. Upstream Spatie calls setters in some subclass paths; in Hypervel, lookup methods must pass the requested model class into registrar query helpers so one coroutine cannot change the class used by another coroutine.

Forbidden logic should fit into canonical methods:

```php
public function hasPermissionTo($permission, ?string $guardName = null): bool
{
    if ($this->hasForbiddenPermission($permission, $guardName)) {
        return false;
    }

    if ($this->hasForbiddenPermissionViaRoles($permission, $guardName)) {
        return false;
    }

    if ($this->getWildcardClass()) {
        return $this->hasWildcardPermission($permission, $guardName);
    }

    $permission = $this->filterPermission($permission, $guardName);

    return $this->hasDirectPermission($permission) || $this->hasPermissionViaRole($permission);
}
```

When merging the archived forbidden-permission behavior, do not copy the old rough edges:

- use strict comparisons and `(bool)` pivot casts for `is_forbidden`
- use `getKey()` / `getKeyName()` and configured pivot keys, never hardcoded `id`

For role models, `hasPermissionTo()` must still validate guard compatibility against the permission's guard.

### Wildcard Permissions

Port `Contracts\Wildcard`, `WildcardPermission`, wildcard exceptions, and all wildcard-related trait behavior.

Keep Spatie's algorithm. It is already optimized upstream and includes recent performance work. Do not add a worker-lifetime wildcard index unless implementation proves it is needed and can be invalidated with the same model assignment versioning. The first fresh port should keep the wildcard behavior easy to compare with upstream.

Forbidden permissions must apply before wildcard allows.

Forbidden checks that run before the wildcard path must match the input against the subject's forbidden permission names, IDs, and enum values without routing wildcard-pattern strings through `filterPermission()`. A wildcard input such as `posts.*` should not throw `PermissionDoesNotExist` before the wildcard matcher has a chance to evaluate it.

### Events

Port event classes:

```text
RoleAttachedEvent
RoleDetachedEvent
PermissionAttachedEvent
PermissionDetachedEvent
```

Adapt dispatching:

```php
if (Config::eventsEnabled() && $events->hasListeners(RoleAttachedEvent::class)) {
    $events->dispatch(new RoleAttachedEvent($this->getModel(), $roles));
}
```

This keeps the event path free when no listener exists.

### Middleware

Port:

- `RoleMiddleware`
- `PermissionMiddleware`
- `RoleOrPermissionMiddleware`

Use Spatie's API:

- pipe-separated values
- array and enum input in `using()`
- optional guard argument
- Passport client fallback
- `canAny()` for permission checks
- `hasAnyRole()` for role checks

Hypervel currently has only role and permission middleware and uses `hasAnyRoles` / `hasAnyPermissions`. The fresh port should use Spatie canonical singular names:

- `hasAnyRole`
- `hasAnyPermission`

Do not keep the old plural compatibility wrappers. Update tests and docs to the canonical Spatie names.

### Blade Directives

Port Spatie's directives:

- `@haspermission`
- `@role`
- `@hasrole`
- `@hasanyrole`
- `@hasallroles`
- `@hasexactroles`
- `@unlessrole`
- `@endunlessrole`

Use `callAfterResolving('blade.compiler', ...)` in the provider.

### Route Macros

Port route macros:

- `role(...)`
- `permission(...)`
- `roleOrPermission(...)`

The macros convert arrays/enums into pipe-separated middleware values and return the route with middleware attached.

### Commands

Port:

- `permission:cache-reset`
- `permission:create-role`
- `permission:create-permission`
- `permission:show`
- `permission:assign-role`
- `permission:setup-teams`

Use Symfony `#[AsCommand]`.

`permission:setup-teams` should publish or create the teams migration stub, matching Spatie's purpose while using Hypervel paths and command patterns. It is useful when teams are enabled after the base permission migration has already run.

### Exceptions

Port upstream exception classes and replace the current broad `PermissionException` / `RoleException` shape with Spatie's typed exceptions.

Keep accessors that tests and users need:

- `UnauthorizedException::getRequiredRoles()`
- `UnauthorizedException::getRequiredPermissions()`

Use Hypervel translator helper/function behavior for messages.

### README And Boost Docs

Update the package README to include:

- `Ported from: https://github.com/spatie/laravel-permission`
- current status for Hypervel 0.4
- installation
- key differences from Spatie Laravel Permission
- forbidden permissions
- Swoole/cache notes
- Passport support

Add `Differences From Spatie Laravel Permission`:

```md
## Differences From Spatie Laravel Permission

- Laravel Octane reset listeners are not included. Hypervel is Swoole-native:
  request/team state uses coroutine context, and permission cache freshness is
  handled by configured cache stores, per-coroutine memoization, stack cache,
  and explicit invalidation.
- Hypervel adds forbidden permissions. A forbidden permission explicitly denies
  an ability and wins over direct or role-granted allows.
```

Update `src/boost/docs/permission.md` to match the fresh port:

- Spatie plural trait names.
- `model_*` schema names.
- teams.
- wildcards.
- guards.
- Passport.
- events.
- commands.
- route macros.
- Blade directives.
- forbidden permissions.
- cache memo / stack advice.

Remove old `owner_*` docs.

## Composer And Autoloading

Keep `src/permission/composer.json` wired for subtree split:

- package name `hypervel/permission`
- provider discovery for `Hypervel\Permission\PermissionServiceProvider`
- autoload `Hypervel\Permission\`
- autoload file `helpers.php`
- dependencies on `hypervel/view`, `hypervel/routing`, and `hypervel/events`, in addition to the existing auth/cache/console/contracts/database/http/support dependencies

Root `composer.json` must include `src/permission/src/helpers.php` in autoload files. The helpers define:

- `getModelForGuard`
- `setPermissionsTeamId`
- `getPermissionsTeamId`

The root autoload file list currently does not include permission helpers, so this must be added.

No new third-party dependency is needed.

Package composer autoload:

```json
"autoload": {
    "psr-4": {
        "Hypervel\\Permission\\": "src/"
    },
    "files": [
        "src/helpers.php"
    ]
}
```

Root composer autoload should add the helper next to the other package helper files:

```json
"files": [
    "src/prompts/src/helpers.php",
    "src/reflection/src/helpers.php",
    "src/permission/src/helpers.php"
]
```

## Implementation Steps

### 1. Archive Current Package Body

Move the current implementation into `_archive/permission` before porting. Keep:

- `src/permission/composer.json`
- `src/permission/README.md`
- `src/permission/LICENSE.md`

Why: this gives a clean slate while preserving the existing Hypervel-specific behavior for reference.

### 2. Copy Upstream Source Files One At A Time

Follow the components `AGENTS.md` porting workflow:

1. Copy the upstream file with `cp`.
2. Read the copied file in full.
3. Update namespace/imports/types/docblocks.
4. Adapt Laravel-only internals to Hypervel.
5. Run focused checks for the copied file before moving to the next related file.

Port files alphabetically within each directory. Keep method order matching upstream.

### 3. Build The Hypervel Registrar And Config Layer

Implement `Support\Config` first enough for the rest of the package to compile. Then implement `DefaultTeamResolver` and `PermissionRegistrar`.

Why: nearly every model/trait calls through these classes.

Key adaptations:

- typed injected config repository
- no runtime config mutation
- configured cache repository plus memo wrapper
- forbidden pivot data in serialized cache
- coroutine-safe team id resolver
- cache key helpers for model role/direct permission caches
- static `flushState()` for test cleanup

### 4. Port Models And Traits

Port `Role`, `Permission`, and traits. Merge forbidden permission support into `HasPermissions` after the Spatie base is compiling.

Why: this preserves upstream behavior first, then layers Hypervel's improvement into the correct points.

Forbidden methods to add:

```php
public function giveForbiddenTo(...$permissions): static;
public function hasForbiddenPermission($permission, ?string $guardName = null): bool;
public function hasForbiddenPermissionViaRoles($permission, ?string $guardName = null): bool;
```

`syncPermissions()` keeps Spatie's variadic API and return type:

```php
$model->syncPermissions(['edit articles', 'delete articles']);
```

Add a dedicated dual-list helper for forbidden sync so Spatie call sites stay intact:

```php
public function syncPermissionsWithForbidden(array|Collection $allowed = [], array|Collection $forbidden = []): array;
```

The helper accepts allowed and forbidden permission lists, syncs both direct-pivot sets, invalidates direct permission caches, and returns the `BelongsToMany::sync()` change array. `syncPermissions(...$permissions)` must continue to return `static`, matching Spatie.

### 5. Port Middleware, Events, Blade, Route Macros, Commands

Port these after the models/traits are in place.

Why: they depend on the canonical trait API and exception types.

Register route macros and middleware aliases in the provider.

### 6. Wire Composer Autoload

Add `src/permission/src/helpers.php` to root `composer.json` autoload files and verify `src/permission/composer.json` includes the file autoload too.

Run:

```sh
composer dump-autoload
```

### 7. Update Docs

Update:

- `src/permission/README.md`
- `src/boost/docs/permission.md`

Do targeted edits; do not replace unrelated docs.

### 8. Add Test Cleanup

Add package cleanup to `tests/AfterEachTestSubscriber.php`:

```php
\Hypervel\Permission\DefaultTeamResolver::flushState();
\Hypervel\Permission\PermissionRegistrar::flushState();
```

Only add cleanup for actual static or coroutine-held state. Do not add permission cleanup to testbench application flush helpers.

## Test Port Plan

Port upstream tests from `/tmp/spatie-laravel-permission/tests` into `tests/Permission`, converting Pest tests to PHPUnit.

Keep file layout close to upstream:

```text
tests/Permission/Commands
tests/Permission/Integration
tests/Permission/Middleware
tests/Permission/Models
tests/Permission/Traits
tests/Permission/Fixtures
```

Use `Hypervel\Testbench\TestCase` for integration tests needing container, DB, routes, Blade, auth, or files. Use `Hypervel\Tests\TestCase` only for pure unit tests.

### Test Harness

Create `tests/Permission/TestCase.php` as the package base test case. It should:

- extend `Hypervel\Testbench\TestCase`
- use `RefreshDatabase`
- configure auth guards/providers for the fixture user models
- set `permission.storage.database.connection` to the test database connection, or to `null` when the test database connection is already the framework default
- configure a deterministic permission cache store for tests
- after applying permission/cache config in `setUp()`, call `$this->app->forgetInstance(PermissionRegistrar::class)` so the registrar singleton is rebuilt from that test's config
- flush the configured permission cache store in `setUp()` so catalog and per-subject cache entries cannot leak across tests

Team-specific tests should enable teams before reconstructing the registrar and should seed team ids inside `setUpInCoroutine()` or the test method when coroutine-local state is required.

### Upstream Tests To Port

Port and adapt:

```text
Commands/CommandTest.php
Commands/TeamCommandTest.php
Integration/BladeTest.php
Integration/CacheTest.php
Integration/CustomGateTest.php
Integration/GateTest.php
Integration/MultipleGuardsTest.php
Integration/PermissionRegistrarTest.php
Integration/PolicyTest.php
Integration/RouteTest.php
Integration/WildcardRouteTest.php
Middleware/PermissionMiddlewareTest.php
Middleware/RoleMiddlewareTest.php
Middleware/RoleOrPermissionMiddlewareTest.php
Middleware/WildcardMiddlewareTest.php
Models/PermissionTest.php
Models/RoleTest.php
Models/RoleWithNestingTest.php
Models/TestPermissionEnum.php
Models/TestRoleEnum.php
Models/WildcardRoleTest.php
Traits/HasAssignedModelsTest.php
Traits/HasPermissionsTest.php
Traits/HasPermissionsWithCustomModelsTest.php
Traits/HasRolesTest.php
Traits/HasRolesWithCustomModelsTest.php
Traits/TeamHasPermissionsTest.php
Traits/TeamHasRolesTest.php
Traits/TeamScopeTest.php
Traits/WildcardHasPermissionsTest.php
```

Port upstream test support:

```text
TestSupport/TestCase.php
TestSupport/ContentPolicy.php
TestSupport/TestHelper.php
TestSupport/TestModels/*
TestSupport/resources/views/*
```

Do not keep Pest bootstrap files. Read upstream `TestSupport/TestCase.php` as the fixture/setup source, then convert only the needed setup into Hypervel PHPUnit test cases.

### Intentional Test Difference Comments

For Octane reset listener tests, add:

```php
// REMOVED: Laravel Octane reset listeners are not part of Hypervel. Team state
// is coroutine-scoped and cache freshness is handled by the cache layer.
```

Only add these comments where upstream tests would otherwise be ported.

### Extra Hypervel Tests

Add tests beyond upstream where Hypervel architecture or improvements require proof.

#### Forbidden Permissions

Cover:

- direct forbidden permission denies `hasPermissionTo()`
- direct forbidden denies `can()` through Gate
- role forbidden denies role-granted allow
- role forbidden denies direct allow
- forbidden wins when allowed and forbidden are both passed
- forbidden permissions inherited through roles work with custom role and permission primary keys
- pure unit enums work for role and permission assignment/check paths
- `getAllPermissions()` excludes forbidden permissions
- `getPermissionsViaRoles()` excludes forbidden role permissions
- `syncPermissions()` keeps Spatie behavior
- forbidden sync helper returns correct attached/detached/updated data
- role-permission forbidden pivot is included in global cache
- model direct forbidden pivot is included in model direct-permission cache

#### Cache And Memo

Cover:

- repeated permission checks in one coroutine do not repeatedly hit the underlying configured cache store
- `Cache::memo()` over a configured stack store works for permission cache reads
- model direct role cache invalidates after `assignRole`, `removeRole`, and `syncRoles`
- model direct permission cache invalidates after `givePermissionTo`, `giveForbiddenTo`, `revokePermissionTo`, and forbidden sync helper
- role/permission model save/delete clears global permission cache
- role/permission model save/delete bumps the model assignment-cache version so old per-subject cache entries are bypassed
- `permission:cache-reset` clears the global catalog and bumps the model assignment-cache version
- `HasAssignedModels` reverse assignment clears each touched model's per-subject cache
- custom role/permission primary keys are used in cache maps, not hardcoded `id`
- team id is included in model assignment cache keys when teams are enabled

The existing `tests/Cache/CacheMemoizedStoreTest.php` already proves memo-over-stack at the cache component level. Permission tests should prove the permission registrar uses that path, not retest every cache store behavior.

#### Coroutine Safety

Cover:

- two concurrent coroutines can set different permission team ids and read them back without leakage
- role/permission checks in concurrent team contexts use the correct team assignments
- changing team id in one coroutine does not affect another coroutine's permission cache key
- `PermissionRegistrar::flushState()` and `DefaultTeamResolver::flushState()` clean test state

Use:

```php
use function Hypervel\Coroutine\parallel;

[$first, $second] = parallel([
    function () {
        setPermissionsTeamId(1);
        usleep(5000);

        return getPermissionsTeamId();
    },
    function () {
        setPermissionsTeamId(2);
        usleep(5000);

        return getPermissionsTeamId();
    },
]);

$this->assertSame([1, 2], [$first, $second]);
```

#### Events

Cover:

- no event is dispatched when `events_enabled` is false
- no event object is constructed/dispatched when enabled but no listener exists
- correct event dispatches when a listener exists
- events include role/permission IDs or models matching upstream expectations

#### Passport

Cover with fakes:

- middleware falls back to fake Passport client when no user exists and request has bearer token
- no Passport path is used when `use_passport_client_credentials` is false
- client guard mismatch denies
- client can pass role middleware
- client can pass permission middleware
- client can pass role-or-permission middleware

The tests must not depend on a real Hypervel Passport package.

#### Schema And Config

Cover:

- default table names are Spatie-compatible
- custom table names are honored by migration and relations
- custom pivot key names are honored
- configured database connection is honored by migration/models
- teams enabled schema creates team columns and team-aware unique keys
- teams disabled schema does not create team columns
- `permission:setup-teams` publishes or creates the teams migration stub when teams are enabled after install
- `model_morph_key` customization works
- no `owner_*` names remain in source, tests, or docs except in archived reference files

#### Docs / Public API Smoke

Cover:

- route macros attach expected middleware strings
- Blade directives compile and evaluate correctly
- command registration includes all ported commands
- `permission:setup-teams` is registered
- middleware aliases are registered
- helpers are autoloaded

### Test Cadence

For each test file:

```sh
./vendor/bin/phpunit --no-progress tests/Permission/Path/To/Test.php
```

After each logical group:

```sh
composer fix
```

`composer fix` runs cs-fixer, PHPStan, and `composer test:parallel`. The full suite must be green before the work is complete.

## Acceptance Criteria

- The package source is a fresh Hypervel port of the current upstream Spatie package.
- Public APIs match Spatie unless this plan records an intentional Hypervel difference.
- Forbidden permissions are preserved and fully tested.
- Teams are coroutine-safe.
- Passport client-credentials support is present and fake-tested.
- Events stay dormant when no listeners exist.
- Cache reads use configured cache plus per-coroutine memoization.
- Mutable permission assignment data is not stored only in worker-local static state.
- `owner_*` naming is gone from new source, tests, config, docs, and migrations.
- `permission:setup-teams` is registered and tested.
- Laravel Octane listener behavior is not registered, with durable comments explaining why.
- README and Boost docs match the implemented API.
- Root and package Composer files autoload helpers correctly.
- `composer fix` passes.

## Fresh-Session Checklist

1. Re-read monorepo root `CLAUDE.md`.
2. Re-read components repo `AGENTS.md`.
3. Pull `/tmp/spatie-laravel-permission`.
4. Confirm current package has been archived and only skeleton files remain.
5. Copy and port source files one at a time from upstream.
6. Merge the archived Hypervel forbidden-permission and cache improvements into the Spatie-shaped implementation.
7. Port tests file by file, running each file immediately.
8. Add Hypervel-specific tests listed above.
9. Update README and Boost docs.
10. Run `composer fix`.
11. Self-review against upstream source, archived Hypervel source, tests, docs, and this plan.
