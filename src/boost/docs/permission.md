# Permission

- [Introduction](#introduction)
- [Installation](#installation)
    - [Publishing Files](#publishing-files)
    - [Running Migrations](#running-migrations)
- [Configuration](#configuration)
    - [Models](#models)
    - [Database Connection](#database-connection)
    - [Table and Column Names](#table-and-column-names)
    - [Cache](#cache)
- [Model Setup](#model-setup)
- [Creating Roles and Permissions](#creating-roles-and-permissions)
    - [Creating Permissions](#creating-permissions)
    - [Creating Roles](#creating-roles)
    - [Assigning Permissions to Roles](#assigning-permissions-to-roles)
- [Working With Roles](#working-with-roles)
    - [Assigning Roles](#assigning-roles)
    - [Checking Roles](#checking-roles)
    - [Removing Roles](#removing-roles)
- [Working With Permissions](#working-with-permissions)
    - [Assigning Permissions](#assigning-permissions)
    - [Checking Permissions](#checking-permissions)
    - [Forbidden Permissions](#forbidden-permissions)
    - [Revoking Permissions](#revoking-permissions)
    - [Retrieving Permissions](#retrieving-permissions)
- [Using Enums](#using-enums)
- [Middleware](#middleware)
    - [Permission Middleware](#permission-middleware)
    - [Role Middleware](#role-middleware)
- [Console Commands](#console-commands)
- [Polymorphic Owners](#polymorphic-owners)
- [Custom Models](#custom-models)
- [Caching](#caching)
- [Performance](#performance)

<a name="introduction"></a>
## Introduction

Hypervel's permission package provides role-based access control for Eloquent models. You may create roles and permissions, assign them to users or other models, and check access by role, direct permission, or permission inherited through a role.

The package is based on Spatie's `laravel-permission` package and adapted for Hypervel. It also supports forbidden permissions, which explicitly deny an ability even when the model receives the same permission directly or through a role.

<a name="installation"></a>
## Installation

You may install the package using Composer:

```shell
composer require hypervel/permission
```

The package service provider is discovered automatically.

<a name="publishing-files"></a>
### Publishing Files

You may publish the configuration file and migration using the `vendor:publish` command:

```shell
php artisan vendor:publish --provider="Hypervel\Permission\PermissionServiceProvider"
```

You may also publish the files separately using their tags:

```shell
php artisan vendor:publish --tag=permission-config

php artisan vendor:publish --tag=permission-migrations
```

<a name="running-migrations"></a>
### Running Migrations

After publishing the migration, run your database migrations:

```shell
php artisan migrate
```

The published migration creates the following tables:

- `roles`
- `permissions`
- `role_has_permissions`
- `owner_has_permissions`
- `owner_has_roles`

The `role_has_permissions` and `owner_has_permissions` tables include an `is_forbidden` column used by forbidden permissions.

> [!WARNING]
> If you customize the table or column names in the permission configuration file, update the published migration before running it.

> [!NOTE]
> The default migration makes role names and permission names unique. If you need to reuse the same name across multiple guards, update those indexes in the published migration before running it.

<a name="configuration"></a>
## Configuration

<a name="models"></a>
### Models

You may customize the models used for roles and permissions:

```php
'models' => [
    'role' => App\Models\Role::class,
    'permission' => App\Models\Permission::class,
],
```

Custom role models must implement the `Hypervel\Permission\Contracts\Role` contract. Custom permission models must implement the `Hypervel\Permission\Contracts\Permission` contract. The easiest way to satisfy these contracts is to extend the package's base models.

<a name="database-connection"></a>
### Database Connection

You may store the permission tables on a specific database connection:

```php
'storage' => [
    'database' => [
        'connection' => env('DB_CONNECTION', 'mysql'),
    ],
],
```

The published migration reads this value when choosing its migration connection.

<a name="table-and-column-names"></a>
### Table and Column Names

You may customize the table names used by the relationships:

```php
'table_names' => [
    'roles' => 'roles',
    'permissions' => 'permissions',
    'role_has_permissions' => 'role_has_permissions',
    'owner_has_permissions' => 'owner_has_permissions',
    'owner_has_roles' => 'owner_has_roles',
],
```

You may also customize the pivot and morph column names:

```php
'column_names' => [
    'role_pivot_key' => 'role_id',
    'permission_pivot_key' => 'permission_id',
    'owner_morph_key' => 'owner_id',
    'owner_name' => 'owner',
],
```

<a name="cache"></a>
### Cache

The package caches role and permission data to reduce database queries during permission checks:

```php
'cache' => [
    'expiration_seconds' => 86400,
    'keys' => [
        'roles' => 'hypervel.permission.cache.roles',
        'owner_roles' => 'hypervel.permission.cache.owner.roles',
        'owner_permissions' => 'hypervel.permission.cache.owner.permissions',
    ],
    'store' => env('PERMISSION_CACHE_STORE', 'default'),
],
```

When `store` is `default`, the application's default cache store is used. If an unknown cache store is configured, the permission manager falls back to the `array` cache store.

<a name="model-setup"></a>
## Model Setup

To assign roles and permissions to a model, add the `Hypervel\Permission\Traits\HasRole` trait:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Traits\HasRole;

class User extends Model
{
    use HasRole;
}
```

The `HasRole` trait includes the `HasPermission` trait, so a model using `HasRole` may receive roles and direct permissions.

<a name="creating-roles-and-permissions"></a>
## Creating Roles and Permissions

<a name="creating-permissions"></a>
### Creating Permissions

You may create permissions using the package's `Permission` model:

```php
use Hypervel\Permission\Models\Permission;

$editArticles = Permission::create([
    'name' => 'edit articles',
    'guard_name' => 'web',
]);

$deleteArticles = Permission::create([
    'name' => 'delete articles',
    'guard_name' => 'web',
]);
```

<a name="creating-roles"></a>
### Creating Roles

You may create roles using the package's `Role` model:

```php
use Hypervel\Permission\Models\Role;

$writer = Role::create([
    'name' => 'writer',
    'guard_name' => 'web',
]);

$editor = Role::create([
    'name' => 'editor',
    'guard_name' => 'web',
]);
```

<a name="assigning-permissions-to-roles"></a>
### Assigning Permissions to Roles

Roles use the same permission methods as other models:

```php
use Hypervel\Permission\Models\Role;

$role = Role::where('name', 'writer')->firstOrFail();

$role->givePermissionTo('edit articles');

$role->givePermissionTo('delete articles', 'publish articles');

$role->syncPermissions(['edit articles', 'publish articles']);
```

To replace a role's allowed and forbidden permissions at the same time, pass forbidden permissions as the second argument to `syncPermissions`:

```php
$role->syncPermissions(
    ['edit articles'],
    ['delete articles'],
);
```

<a name="working-with-roles"></a>
## Working With Roles

<a name="assigning-roles"></a>
### Assigning Roles

You may assign roles by name, ID, enum, array, or variadic arguments:

```php
$user->assignRole('writer');

$user->assignRole('writer', 'editor');

$user->assignRole(['writer', 'editor']);

$user->assignRole($writer->id);
```

To replace all of a model's roles, use `syncRoles`:

```php
$user->syncRoles('writer', 'editor');
```

<a name="checking-roles"></a>
### Checking Roles

You may check a model's assigned roles:

```php
if ($user->hasRole('writer')) {
    // ...
}

if ($user->hasAnyRoles(['writer', 'editor'])) {
    // ...
}

if ($user->hasAllRoles(['writer', 'editor'])) {
    // ...
}
```

You may retrieve only the roles that match a given list:

```php
$matchingRoles = $user->onlyRoles(['writer', 'admin']);
```

<a name="removing-roles"></a>
### Removing Roles

You may remove one or more roles from a model:

```php
$user->removeRole('writer');

$user->removeRole('writer', 'editor');
```

<a name="working-with-permissions"></a>
## Working With Permissions

<a name="assigning-permissions"></a>
### Assigning Permissions

You may assign permissions directly to a model:

```php
$user->givePermissionTo('edit articles');

$user->givePermissionTo('edit articles', 'delete articles');

$user->givePermissionTo(['edit articles', 'delete articles']);
```

To replace all of a model's direct permissions, use `syncPermissions`:

```php
$user->syncPermissions(['edit articles', 'publish articles']);
```

<a name="checking-permissions"></a>
### Checking Permissions

The `hasPermission` method checks direct permissions and permissions inherited through roles:

```php
if ($user->hasPermission('edit articles')) {
    // ...
}
```

You may also check direct permissions or role permissions separately:

```php
if ($user->hasDirectPermission('edit articles')) {
    // ...
}

if ($user->hasPermissionViaRoles('edit articles')) {
    // ...
}
```

You may check whether a model has any or all of a given set of permissions:

```php
if ($user->hasAnyPermissions(['edit articles', 'delete articles'])) {
    // ...
}

if ($user->hasAllPermissions(['edit articles', 'delete articles'])) {
    // ...
}
```

To check only direct permissions, use `hasAnyDirectPermissions` or `hasAllDirectPermissions`:

```php
if ($user->hasAnyDirectPermissions(['edit articles', 'delete articles'])) {
    // ...
}

if ($user->hasAllDirectPermissions(['edit articles', 'delete articles'])) {
    // ...
}
```

<a name="forbidden-permissions"></a>
### Forbidden Permissions

Forbidden permissions explicitly deny access. A forbidden permission overrides an allowed permission, including permissions inherited through roles:

```php
$user->givePermissionTo('delete articles');

$user->giveForbiddenTo('delete articles');

$user->hasPermission('delete articles');

// false
```

You may check whether a forbidden permission exists directly on the model or through its roles:

```php
if ($user->hasForbiddenPermission('delete articles')) {
    // ...
}

if ($user->hasForbiddenPermissionViaRoles('delete articles')) {
    // ...
}
```

The second argument to `syncPermissions` contains forbidden permissions. If a permission is present in both arrays, the forbidden permission wins:

```php
$user->syncPermissions(
    ['view articles', 'edit articles'],
    ['edit articles', 'delete articles'],
);
```

<a name="revoking-permissions"></a>
### Revoking Permissions

You may remove permissions from a model:

```php
$user->revokePermissionTo('edit articles');

$user->revokePermissionTo('edit articles', 'delete articles');
```

<a name="retrieving-permissions"></a>
### Retrieving Permissions

You may retrieve the permissions a model receives directly and through roles:

```php
$permissions = $user->getAllPermissions();
```

To retrieve only permissions inherited through roles, use `getPermissionsViaRoles`:

```php
$rolePermissions = $user->getPermissionsViaRoles();
```

Forbidden permissions are excluded from these result sets.

<a name="using-enums"></a>
## Using Enums

Role and permission methods accept backed enums and unit enums. Backed enums use their `value`; unit enums use their case `name`.

```php
enum Permission: string
{
    case EditArticles = 'edit articles';
    case DeleteArticles = 'delete articles';
    case PublishArticles = 'publish articles';
}

enum Role: string
{
    case Writer = 'writer';
    case Editor = 'editor';
    case Admin = 'admin';
}
```

You may pass enum cases to role and permission methods:

```php
$user->assignRole(Role::Writer);

$user->givePermissionTo(Permission::EditArticles);

if ($user->hasPermission(Permission::EditArticles)) {
    // ...
}
```

Unit enum case names are used as the role or permission name:

```php
enum SimplePermission
{
    case EditArticles;
    case DeleteArticles;
}

$user->givePermissionTo(SimplePermission::EditArticles);
```

<a name="middleware"></a>
## Middleware

The package includes route middleware for checking roles and permissions. Middleware checks require the authenticated user model to use the matching permission methods.

<a name="permission-middleware"></a>
### Permission Middleware

Use `PermissionMiddleware::using` to protect a route by permission:

```php
use App\Http\Controllers\AdminController;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Support\Facades\Route;

Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(PermissionMiddleware::using('view admin'));
```

When multiple permissions are provided, the user only needs one of them:

```php
Route::get('/posts/edit', [PostController::class, 'edit'])
    ->middleware(PermissionMiddleware::using('edit articles', 'edit all articles'));
```

<a name="role-middleware"></a>
### Role Middleware

Use `RoleMiddleware::using` to protect a route by role:

```php
use App\Http\Controllers\AdminController;
use Hypervel\Permission\Middleware\RoleMiddleware;
use Hypervel\Support\Facades\Route;

Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(RoleMiddleware::using('admin'));
```

When multiple roles are provided, the user only needs one of them:

```php
Route::get('/editor', [EditorController::class, 'index'])
    ->middleware(RoleMiddleware::using('editor', 'admin'));
```

Middleware may also receive enum cases:

```php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(PermissionMiddleware::using(Permission::EditArticles));

Route::get('/editor', [EditorController::class, 'index'])
    ->middleware(RoleMiddleware::using(Role::Editor, Role::Admin));
```

If the user is not authenticated, the middleware throws `Hypervel\Permission\Exceptions\UnauthorizedException`. If the user is authenticated but does not have the required role or permission, it throws `Hypervel\Permission\Exceptions\RoleException` or `Hypervel\Permission\Exceptions\PermissionException`.

<a name="console-commands"></a>
## Console Commands

You may view the permission matrix using the `permission:show` command:

```shell
php artisan permission:show
```

You may limit the output to a specific guard:

```shell
php artisan permission:show web
```

The command supports the `default`, `borderless`, `compact`, and `box` table styles:

```shell
php artisan permission:show web compact
```

<a name="polymorphic-owners"></a>
## Polymorphic Owners

Roles and permissions use polymorphic relationships, so any Eloquent model may receive them:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Traits\HasRole;

class Team extends Model
{
    use HasRole;
}
```

```php
$team->assignRole('project-manager');

$team->givePermissionTo('manage projects');
```

<a name="custom-models"></a>
## Custom Models

You may extend the package's base models to add your own behavior:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Permission\Models\Permission as BasePermission;

class Permission extends BasePermission
{
    // ...
}
```

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    // ...
}
```

After creating custom models, update the permission configuration:

```php
'models' => [
    'permission' => App\Models\Permission::class,
    'role' => App\Models\Role::class,
],
```

<a name="caching"></a>
## Caching

The permission manager caches all roles with their permissions, each owner's roles, and each owner's direct permissions. The package clears the relevant cache entries when roles or permissions are assigned, removed, or synchronized.

You may clear all cached role-permission data:

```php
use Hypervel\Permission\PermissionManager;

$manager = app(PermissionManager::class);

$manager->clearAllRolesPermissionsCache();
```

You may clear the cached roles and permissions for a specific owner:

```php
$manager->clearOwnerCache(User::class, $user->getKey());
```

You may warm the global role-permission cache during deployment:

```php
$manager->getAllRolesWithPermissions();
```

<a name="performance"></a>
## Performance

Permission checks use cached role and permission data after the first lookup. This keeps repeated checks inexpensive while still allowing role and permission changes to invalidate the affected cache entries.

If you need to display a model's roles or permissions, eager load the relationships you will render:

```php
$users = User::with(['roles.permissions', 'permissions'])->get();
```

Eager loading is not required for normal `hasPermission` or `hasRole` checks, since those checks use the package cache.
