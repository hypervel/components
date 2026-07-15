# Hypervel Permission

Ported from: https://github.com/spatie/laravel-permission

This package provides Spatie-style roles and permissions for Hypervel applications, adapted for long-lived Swoole workers and coroutine-safe request state.

## Features

- Roles and permissions for Eloquent models via `Hypervel\Permission\Traits\HasRoles`.
- Permission checks through model methods, Gate, middleware, Blade directives, and route macros.
- Teams support with coroutine-scoped current team state.
- Wildcard permissions.
- Passport client-credentials middleware support.
- Hypervel-only denied permissions, where an explicit deny wins over direct or role-granted allows.
- Opt-in generic row partitioning across queries, relations, assignments, caches, and invalidation.
- Configured cache store plus per-coroutine memoization for hot permission checks.

## Installation

```shell
composer require hypervel/permission
```

Publish the config and migration:

```shell
php artisan vendor:publish --provider="Hypervel\Permission\PermissionServiceProvider"
php artisan migrate
```

Add `HasRoles` to any model that should receive roles and permissions:

```php
use Hypervel\Permission\Traits\HasRoles;

class User extends Model
{
    use HasRoles;
}
```

## Differences From Spatie Laravel Permission

- Hypervel adds denied permissions. A denied assignment explicitly rejects an ability and wins over direct or role-granted allows. The `is_denied` flag is stored as the effect on the assignment row, so assigning allow or deny for the same model or role and permission updates the existing edge. Use `syncPermissionEffects()` to replace allowed and denied assignments together.
- `getDirectPermissions()`, `getPermissionsViaRoles()`, `getAllPermissions()`, and `getPermissionNames()` return effective allowed permissions. Explicit denied edges are exposed through `hasDeniedPermission()` and `hasDeniedPermissionViaRoles()`.
- Hypervel accepts pure unit enums anywhere enum names are valid role or permission inputs. Backed enums use their values; unit enums use their case names.
- Hypervel's cache config uses `expiration_seconds` and separate named cache keys so role, model-role, model-permission, and assignment-token caches can be invalidated independently.
- Hypervel supports generic row partitioning through `PermissionRegistrar::resolvePartitionUsing(...)`. One application-defined scalar dimension scopes Role and Permission rows, every package-owned pivot operation, relations, query scopes, commands, cache identities, and invalidation. Missing context fails closed. Workspaces, installations, realms, and multi-tenancy are possible uses; Permission does not provide or depend on any one partition domain.
- The stock migration remains unpartitioned. Applications opting in own a schema with the same non-null native partition column on all five authorization tables. Partition-enabled custom Role and Permission models must extend Hypervel's base models so unscoped Eloquent lifecycle operations retain the partition invariant.
- Undefined `permission.cache.store` values fail fast through Hypervel's cache manager instead of silently falling back to an array store.
- The default role and permission models do not use soft deletes. Soft deletes are not recommended for permission models; restoring a custom soft-deletable role or permission reactivates its existing assignments.

Full usage docs are available in `src/boost/docs/permission.md`.
