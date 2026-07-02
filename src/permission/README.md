# Hypervel Permission

Ported from: https://github.com/spatie/laravel-permission

This package provides Spatie-style roles and permissions for Hypervel applications, adapted for long-lived Swoole workers and coroutine-safe request state.

## Features

- Roles and permissions for Eloquent models via `Hypervel\Permission\Traits\HasRoles`.
- Permission checks through model methods, Gate, middleware, Blade directives, and route macros.
- Teams support with coroutine-scoped current team state.
- Wildcard permissions.
- Passport client-credentials middleware support.
- Hypervel-only forbidden permissions, where an explicit deny wins over direct or role-granted allows.
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

- Hypervel adds forbidden permissions. A forbidden permission explicitly denies an ability and wins over direct or role-granted allows. The deny flag is stored as the effect on the assignment row, so assigning allow or deny for the same model or role and permission updates the existing edge.
- Hypervel accepts pure unit enums anywhere enum names are valid role or permission inputs. Backed enums use their values; unit enums use their case names.
- Hypervel's cache config uses `expiration_seconds` and separate named cache keys so role, model-role, model-permission, and assignment-token caches can be invalidated independently.
- Apps where permission data depends on request context, such as multi-tenant apps with tenant-scoped permission tables, may register a runtime cache key resolver with `PermissionRegistrar::resolveCacheKeyUsing(...)` so cached permission catalogs and assignments are isolated per context.

Full usage docs are available in `src/boost/docs/permission.md`.
