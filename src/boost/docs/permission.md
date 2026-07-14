# Permission

- [Introduction](#introduction)
- [Installation](#installation)
    - [Publishing Files](#publishing-files)
    - [Running Migrations](#running-migrations)
- [Configuration](#configuration)
    - [Models](#models)
    - [Table and Column Names](#table-and-column-names)
    - [Cache](#cache)
- [Model Setup](#model-setup)
- [Multiple Guards](#multiple-guards)
- [Creating Roles and Permissions](#creating-roles-and-permissions)
    - [Creating Permissions](#creating-permissions)
    - [Creating Roles](#creating-roles)
    - [Assigning Permissions to Roles](#assigning-permissions-to-roles)
- [Working With Roles](#working-with-roles)
    - [Assigning Roles](#assigning-roles)
    - [Assigning Models to a Role](#assigning-models-to-a-role)
    - [Checking Roles](#checking-roles)
    - [Role and Team Scopes](#role-and-team-scopes)
    - [Removing Roles](#removing-roles)
- [Working With Permissions](#working-with-permissions)
    - [Assigning Permissions](#assigning-permissions)
    - [Checking Permissions](#checking-permissions)
    - [Gate and Super Admins](#gate-and-super-admins)
    - [Forbidden Permissions](#forbidden-permissions)
    - [Revoking Permissions](#revoking-permissions)
    - [Retrieving Permissions](#retrieving-permissions)
- [Using Enums](#using-enums)
- [Middleware](#middleware)
    - [Permission Middleware](#permission-middleware)
    - [Role Middleware](#role-middleware)
    - [Role Or Permission Middleware](#role-or-permission-middleware)
    - [Passport Client Credentials](#passport-client-credentials)
- [Blade Directives](#blade-directives)
- [Route Macros](#route-macros)
- [Custom Permission Checks](#custom-permission-checks)
- [Events](#events)
- [Console Commands](#console-commands)
- [Row Partitioning](#row-partitioning)
    - [Registering a Partition](#registering-a-partition)
    - [Partitioned Schema](#partitioned-schema)
    - [Partition Context](#partition-context)
    - [Partitions, Teams, and Guards](#partitions-teams-and-guards)
    - [Partition Cache and Performance](#partition-cache-and-performance)
    - [Raw and Bulk Writes](#raw-and-bulk-writes)
- [Teams](#teams)
- [Wildcard Permissions](#wildcard-permissions)
- [Polymorphic Models](#polymorphic-models)
- [Custom Models](#custom-models)
- [UUID and ULID Keys](#uuid-and-ulid-keys)
- [Caching](#caching)
- [Testing and Seeding](#testing-and-seeding)
- [Best Practices](#best-practices)
- [Performance](#performance)
- [Exceptions](#exceptions)
- [Differences From Spatie Laravel Permission](#differences-from-spatie-laravel-permission)

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
- `model_has_permissions`
- `model_has_roles`

The `role_has_permissions` and `model_has_permissions` tables include an `is_forbidden` column used by forbidden permissions.

> [!WARNING]
> If you customize the table or column names in the permission configuration file, update the published migration before running it.

The default migration uses `['name', 'guard_name']` uniqueness, so the same name may be used by different guards. Applications using row partitioning include the partition column first in that unique key.

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

When row partitioning is enabled, custom Role and Permission models must extend the package's base models. This preserves the partition global scope and the protections for Eloquent instance writes, deletes, refreshes, restoration, and quiet operations.

<a name="table-and-column-names"></a>
### Table and Column Names

You may customize the table names used by the relationships:

```php
'table_names' => [
    'roles' => 'roles',
    'permissions' => 'permissions',
    'role_has_permissions' => 'role_has_permissions',
    'model_has_permissions' => 'model_has_permissions',
    'model_has_roles' => 'model_has_roles',
],
```

You may also customize the pivot and morph column names:

```php
'column_names' => [
    'role_pivot_key' => 'role_id',
    'permission_pivot_key' => 'permission_id',
    'model_morph_key' => 'model_id',
    'team_foreign_key' => 'team_id',
],
```

<a name="cache"></a>
### Cache

The package caches role and permission data to reduce database queries during permission checks:

```php
return [
    'cache' => [
        'expiration_seconds' => 86400,
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
        'keys' => [
            'roles' => 'hypervel.permission.cache.roles',
            'model_roles' => 'hypervel.permission.cache.model.roles',
            'model_permissions' => 'hypervel.permission.cache.model.permissions',
            'model_token' => 'hypervel.permission.cache.model.token',
        ],
        'column_names_except' => ['created_at', 'updated_at', 'deleted_at'],
    ],
];
```

When `store` is `default`, the application's default cache store is used.

You may include required role or permission names in authorization exception messages:

```php
'display_permission_in_exception' => true,
'display_role_in_exception' => true,
```

<a name="model-setup"></a>
## Model Setup

To assign roles and permissions to a model, add the `Hypervel\Permission\Traits\HasRoles` trait:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Traits\HasRoles;

class User extends Model
{
    use HasRoles;
}
```

The `HasRoles` trait includes the permission methods, so a model using `HasRoles` may receive roles and direct permissions.

<a name="multiple-guards"></a>
## Multiple Guards

Roles and permissions are scoped by guard name. If your app uses multiple guards, create the role or permission for the guard that will authorize it:

```php
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;

Role::create(['name' => 'manager', 'guard_name' => 'admin']);

Permission::create(['name' => 'publish articles', 'guard_name' => 'admin']);
```

You may pass the guard name when checking a permission or role:

```php
$user->hasPermissionTo('publish articles', 'admin');

$user->hasRole('manager', 'admin');
```

When a model can use more than one guard, define a `guardName` method or `$guard_name` property:

```php
public function guardName(): array
{
    return ['web', 'admin'];
}
```

If your app uses a single guard for all roles and permissions, return that guard from the model so you do not need duplicate role and permission records:

```php
protected string $guard_name = 'web';
```

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

You may also retrieve existing records or create them if they do not exist:

```php
$role = Role::findByName('writer');

$role = Role::findOrCreate('writer', 'web');

$permission = Permission::findByName('edit articles');

$permission = Permission::findOrCreate('edit articles', 'web');
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

To replace a role's allowed and forbidden permissions at the same time, use `syncPermissionsWithForbidden`:

```php
$role->syncPermissionsWithForbidden(
    allowed: ['edit articles'],
    forbidden: ['delete articles'],
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

<a name="assigning-models-to-a-role"></a>
### Assigning Models to a Role

You may also assign models from the role side:

```php
use Hypervel\Permission\Models\Role;

$role = Role::findByName('writer');

$role->assignToModels([$userA, $userB]);

$role->removeFromModels($userA);

$role->syncModels([$userB, $userC]);
```

These methods accept models, model IDs, arrays, and collections. When you pass raw IDs, pass the model class as the second argument or configure `permission.models.default_model`:

```php
$role->assignToModels([1, 2, 3], App\Models\User::class);
```

<a name="checking-roles"></a>
### Checking Roles

You may check a model's assigned roles:

```php
if ($user->hasRole('writer')) {
    // ...
}

if ($user->hasAnyRole(['writer', 'editor'])) {
    // ...
}

if ($user->hasAllRoles(['writer', 'editor'])) {
    // ...
}
```

<a name="role-and-team-scopes"></a>
### Role and Team Scopes

You may query models by assigned roles:

```php
$writers = User::role('writer')->get();

$usersWithoutWriterRole = User::withoutRole('writer')->get();
```

When teams are enabled, you may also scope models by team:

```php
$teamMembers = User::team($team)->get();

$outsideTeam = User::withoutTeam($team)->get();
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

The `hasPermissionTo` method checks direct permissions and permissions inherited through roles:

```php
if ($user->hasPermissionTo('edit articles')) {
    // ...
}
```

You may also check direct permissions, or inspect permissions inherited through roles:

```php
if ($user->hasDirectPermission('edit articles')) {
    // ...
}

if ($user->getPermissionsViaRoles()->contains('name', 'edit articles')) {
    // ...
}
```

You may check whether a model has any or all of a given set of permissions:

```php
if ($user->hasAnyPermission(['edit articles', 'delete articles'])) {
    // ...
}

if ($user->hasAllPermissions(['edit articles', 'delete articles'])) {
    // ...
}
```

To check only direct permissions, use `hasAnyDirectPermission` or `hasAllDirectPermissions`:

```php
if ($user->hasAnyDirectPermission(['edit articles', 'delete articles'])) {
    // ...
}

if ($user->hasAllDirectPermissions(['edit articles', 'delete articles'])) {
    // ...
}
```

You may query models by permissions:

```php
$editors = User::permission('edit articles')->get();

$usersWithoutEditPermission = User::withoutPermission('edit articles')->get();
```

The `permission` and `withoutPermission` query scopes filter by effective stored permissions. Direct and role-granted denies override allows for the same permission. Wildcard permission strings are evaluated by runtime permission checks such as `hasPermissionTo`; query scopes match stored concrete permission records.

<a name="gate-and-super-admins"></a>
### Gate and Super Admins

The package registers a Gate `before` check by default, so normal authorization calls work with permissions:

```php
if ($user->can('edit articles')) {
    // ...
}
```

For super-admin behavior, register your own Gate `before` callback before normal policy checks:

```php
use Hypervel\Support\Facades\Gate;

Gate::before(function (User $user, string $ability): ?bool {
    return $user->hasRole('super-admin') ? true : null;
});
```

Direct package calls such as `hasPermissionTo` do not pass through Gate callbacks. Use `can`, `canAny`, policies, middleware, or Blade authorization checks when you want Gate-level behavior to apply.

<a name="forbidden-permissions"></a>
### Forbidden Permissions

Forbidden permissions explicitly deny access. The permission assignment tables store `is_forbidden` as the effect for the assignment edge, so a model or role has one row for a given permission in the current team context.

Calling `giveForbiddenTo` for an allowed permission flips that assignment to a deny. Calling `givePermissionTo` for a forbidden permission flips it back to an allow. A forbidden permission overrides an allowed permission, including permissions inherited through roles:

```php
$user->givePermissionTo('delete articles');

$user->giveForbiddenTo('delete articles');

$user->hasPermissionTo('delete articles');

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

Use `syncPermissionsWithForbidden` to replace allowed and forbidden direct permissions together. If a permission is present in both arrays, the forbidden permission wins:

```php
$user->syncPermissionsWithForbidden(
    allowed: ['view articles', 'edit articles'],
    forbidden: ['edit articles', 'delete articles'],
);
```

When this method is called before a model is saved, the assignments are queued
until save and the returned change set is empty because no database rows changed
yet.

<a name="revoking-permissions"></a>
### Revoking Permissions

You may remove permissions from a model:

```php
$user->revokePermissionTo('edit articles');

$user->revokePermissionTo('edit articles', 'delete articles');
```

This removes the assignment edge whether it is currently allowed or forbidden.

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

`getDirectPermissions`, `getPermissionsViaRoles`, `getAllPermissions`, and `getPermissionNames` return allowed permissions. Explicitly forbidden permissions are checked through `hasForbiddenPermission` and `hasForbiddenPermissionViaRoles`.

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

if ($user->hasPermissionTo(Permission::EditArticles)) {
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
    ->middleware(PermissionMiddleware::using(['edit articles', 'edit all articles']));
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
    ->middleware(RoleMiddleware::using(['editor', 'admin']));
```

Middleware may also receive enum cases:

```php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(PermissionMiddleware::using(Permission::EditArticles));

Route::get('/editor', [EditorController::class, 'index'])
    ->middleware(RoleMiddleware::using([Role::Editor, Role::Admin]));
```

<a name="role-or-permission-middleware"></a>
### Role Or Permission Middleware

Use `RoleOrPermissionMiddleware::using` when the user may pass with either a role or a permission:

```php
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;

Route::get('/content', [ContentController::class, 'index'])
    ->middleware(RoleOrPermissionMiddleware::using(['editor', 'edit articles']));
```

If the user is not authenticated or does not have the required role or permission, the middleware throws `Hypervel\Permission\Exceptions\UnauthorizedException`.

<a name="passport-client-credentials"></a>
### Passport Client Credentials

The middleware can authorize Passport client-credentials clients when no authenticated user exists:

```php
'use_passport_client_credentials' => true,
```

The Passport client model must implement Hypervel's `Authorizable` contract and use `HasRoles`:

```php
use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Foundation\Auth\Access\Authorizable;
use Hypervel\Permission\Traits\HasRoles;

// Extend the client model class provided by your Passport package.
class Client extends BaseClient implements AuthorizableContract
{
    use Authorizable;
    use HasRoles;

    protected string $guard_name = 'api';
}
```

Set the client model in Passport, then protect client-credentials routes with this package's role or permission middleware. The permission middleware will use the Passport client when the request has a bearer token and no normal authenticated user.

<a name="blade-directives"></a>
## Blade Directives

The package registers Blade conditionals for roles and permissions:

```blade
@haspermission('edit articles')
    ...
@endhaspermission

@role('admin')
    ...
@endrole

@hasanyrole(['writer', 'editor'])
    ...
@endhasanyrole

@hasallroles(['writer', 'editor'])
    ...
@endhasallroles

@hasexactroles(['writer', 'editor'])
    ...
@endhasexactroles

@unlessrole('guest')
    ...
@endunlessrole
```

Pass the guard name as the second argument when needed:

```blade
@role('admin', 'api')
    ...
@endrole
```

<a name="route-macros"></a>
## Route Macros

Routes also receive permission macros:

```php
Route::get('/admin', [AdminController::class, 'index'])->role('admin');

Route::get('/posts/edit', [PostController::class, 'edit'])->permission('edit articles');

Route::get('/content', [ContentController::class, 'index'])
    ->roleOrPermission(['editor', 'edit articles']);
```

<a name="custom-permission-checks"></a>
## Custom Permission Checks

By default, the package registers a Gate `before` callback that delegates permission checks to `hasPermissionTo`:

```php
'register_permission_check_method' => true,
```

Set this to `false` only when you want to register your own Gate logic:

```php
'register_permission_check_method' => false,
```

```php
use Hypervel\Support\Facades\Gate;

Gate::before(function (User $user, string $ability): ?bool {
    return $user->hasTokenPermission($ability) ?: null;
});
```

<a name="events"></a>
## Events

Role and permission assignment events are disabled by default:

```php
'events_enabled' => false,
```

Enable them when your app listens for assignment changes:

```php
'events_enabled' => true,
```

The package may dispatch these events:

```php
Hypervel\Permission\Events\RoleAttachedEvent::class;
Hypervel\Permission\Events\RoleDetachedEvent::class;
Hypervel\Permission\Events\PermissionAttachedEvent::class;
Hypervel\Permission\Events\PermissionDetachedEvent::class;
```

Events are only dispatched when events are enabled and the event dispatcher has listeners for the event class.

Assignment events preserve Spatie's request-oriented payloads. Role attach/detach and Permission attach events contain the collected requested IDs, including already-satisfied or empty requests. `PermissionDetachedEvent` receives the stored Permission model or collection. Role synchronization reports the pre-operation current Role IDs through its detached event and the requested replacement IDs through its attached event; Permission synchronization emits only its requested attached event.

Assignments made before a subject model is saved are queued on that model and written atomically after save. Their events dispatch synchronously when the assignment method is called, in the caller's established context. The saved callback does not dispatch a duplicate event.

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

Other commands are available for common setup and maintenance tasks:

```shell
php artisan permission:create-role writer
php artisan permission:create-permission "edit articles"
php artisan permission:create-role writer web "edit articles|publish articles"
php artisan permission:assign-role writer 1 web "App\Models\User"
php artisan permission:create-role writer web --team-id=1
php artisan permission:cache-reset
php artisan permission:setup-teams
```

When row partitioning is enabled, data and cache commands operate inside the ambient application partition and fail closed when it is missing. Establish context before invoking them. Permission does not add a generic partition option because the application owns partition identity and enumeration. `permission:setup-teams` remains schema-only.

<a name="row-partitioning"></a>
## Row Partitioning

Row partitioning adds one application-defined scalar dimension to every Permission operation. It is useful when the same subject may have different authorization data in separate workspaces, installations, realms, organizations, environments, or, for example, tenants.

Permission does not provide a partition model, middleware, command option, migration, or context store. The application owns that domain. Permission receives only a column name and the current opaque `int|string` value, then applies it consistently to:

- Role and Permission model queries and lifecycle writes;
- role-permission, model-role, and model-permission relations and pivots;
- assignment, synchronization, reverse-assignment, query-scope, eager-load, wildcard, and forbidden-permission paths;
- console commands and queued Role or Permission restoration;
- shared cache keys, assignment tokens, wildcard indexes, coroutine-local memoization, and invalidation.

Partitioning is opt-in. Without registration, the package retains its normal unpartitioned behavior and schema.

<a name="registering-a-partition"></a>
### Registering a Partition

Register the partition once in an application service provider's `register` method, before the Permission registrar or Gate is resolved:

```php
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Context;

public function register(): void
{
    PermissionRegistrar::resolvePartitionUsing(
        column: 'workspace_id',
        resolver: static fn (): int|string|null => Context::get('workspace_id'),
    );
}
```

The registration is boot-only and persists for the worker lifetime. The resolver itself runs when Permission builds a query, relation, mutation, or cache identity and should read already-populated coroutine context. It must not query the database.

The column must be a simple SQL identifier. Resolver values may be an integer, a non-empty string, or `null`; `0` and `'0'` are valid. The application must use one canonical representation for a partition throughout its lifetime. A missing or empty value throws `PermissionPartitionNotResolved` and never falls back to unpartitioned SQL or cache keys.

Do not register partition callbacks through config files. Hypervel config is worker-lifetime state. Register the callback at boot and read request, job, or command state through `Context`.

<a name="partitioned-schema"></a>
### Partitioned Schema

The stock Permission migration remains unpartitioned. Before running migrations, applications opting in must customize all five authorization tables with the same non-null native partition column:

- `roles`
- `permissions`
- `role_has_permissions`
- `model_has_roles`
- `model_has_permissions`

The following example uses UUID partition, Role, Permission, and subject IDs. Use native integer, UUID, or ULID columns consistently for your own key types:

```php
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;

Schema::create('permissions', function (Blueprint $table): void {
    $table->uuid('workspace_id');
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('guard_name');
    $table->timestamps();

    $table->unique(['workspace_id', 'id']);
    $table->unique(['workspace_id', 'name', 'guard_name']);
});

Schema::create('roles', function (Blueprint $table): void {
    $table->uuid('workspace_id');
    $table->uuid('id')->primary();
    $table->string('name');
    $table->string('guard_name');
    $table->timestamps();

    $table->unique(['workspace_id', 'id']);
    $table->unique(['workspace_id', 'name', 'guard_name']);
});

Schema::create('role_has_permissions', function (Blueprint $table): void {
    $table->uuid('workspace_id');
    $table->uuid('permission_id');
    $table->uuid('role_id');
    $table->boolean('is_forbidden')->default(false);

    $table->primary(['workspace_id', 'permission_id', 'role_id']);

    $table->foreign(['workspace_id', 'permission_id'])
        ->references(['workspace_id', 'id'])
        ->on('permissions')
        ->cascadeOnDelete();

    $table->foreign(['workspace_id', 'role_id'])
        ->references(['workspace_id', 'id'])
        ->on('roles')
        ->cascadeOnDelete();
});

Schema::create('model_has_roles', function (Blueprint $table): void {
    $table->uuid('workspace_id');
    $table->uuid('role_id');
    $table->uuidMorphs('model');

    $table->primary(['workspace_id', 'role_id', 'model_id', 'model_type']);
    $table->index(['workspace_id', 'model_type', 'model_id']);

    $table->foreign(['workspace_id', 'role_id'])
        ->references(['workspace_id', 'id'])
        ->on('roles')
        ->cascadeOnDelete();
});

Schema::create('model_has_permissions', function (Blueprint $table): void {
    $table->uuid('workspace_id');
    $table->uuid('permission_id');
    $table->uuidMorphs('model');
    $table->boolean('is_forbidden')->default(false);

    $table->primary(['workspace_id', 'permission_id', 'model_id', 'model_type']);
    $table->index(['workspace_id', 'model_type', 'model_id']);

    $table->foreign(['workspace_id', 'permission_id'])
        ->references(['workspace_id', 'id'])
        ->on('permissions')
        ->cascadeOnDelete();
});
```

The `['workspace_id', 'id']` unique keys are required targets for the composite foreign keys even when Role and Permission IDs are globally unique primary keys. You may also add a foreign key from `workspace_id` to your own partition-owner table.

Partition-leading primary, unique, and lookup indexes let the database narrow each operation immediately. Keep the partition first wherever Permission always supplies it first.

Polymorphic `(model_type, model_id)` values must identify one subject globally across the shared Permission dataset. UUIDs and ULIDs are the simplest choice. Globally allocated integer IDs are also valid. Reusing the same subject integer ID for unrelated local records in different partitions is not supported because hard deletion must find and remove every assignment belonging to one subject identity.

Partition-enabled custom Role and Permission models must extend the package bases. UUID models may use `HasUuids` normally:

```php
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Models\Permission as BasePermission;
use Hypervel\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    use HasUuids;
}

class Permission extends BasePermission
{
    use HasUuids;
}
```

Configure both model classes under `permission.models`. Extending the bases is required in partitioned mode because they protect Eloquent operations that intentionally bypass global scopes, including instance updates, deletes, refreshes, quiet operations, increments, and queued model restoration.

<a name="partition-context"></a>
### Partition Context

Establish application context before authentication or any Permission operation. A typical request flow resolves the workspace, stores its key in `Context`, authenticates the subject, and then calls normal methods such as `$user->can(...)` or `$user->hasPermissionTo(...)`.

Hypervel propagates Context into queued jobs and hydrates it before serialized Eloquent models are restored. Put the partition value in propagating Context before dispatch. Commands, scheduled tasks, and seeders must establish their own context before resolving Permission models, running Permission commands, or clearing cache.

Role and Permission records always belong to a non-null partition. A global subject model may receive different assignments in several partitions. If a subject itself has the configured partition attribute, Permission rejects assignments when that stored value conflicts with current context.

Permission writes the captured partition value to pivot inserts automatically. Caller-supplied pivot data may omit the partition or repeat the same value, but a conflicting value throws and pivot updates cannot move an existing edge between partitions.

Models loaded with a narrowed `select()` that omits the partition column cannot safely build partitioned relations or perform later lifecycle mutations. Include the partition column whenever a Role or Permission instance will be related, refreshed, saved, restored, or deleted.

<a name="partitions-teams-and-guards"></a>
### Partitions, Teams, and Guards

Partitions, teams, and guards are independent dimensions:

```sql
where workspace_id = ?
  and team_id = ?
  and guard_name = ?
```

A partition is not a Permission team. An application may use partitions without teams or many teams inside one partition. When teams are enabled, place the team column after the partition in relevant keys:

```php
$table->unique(['workspace_id', 'team_id', 'name', 'guard_name']);
$table->primary(['workspace_id', 'team_id', 'role_id', 'model_id', 'model_type']);
$table->primary(['workspace_id', 'team_id', 'permission_id', 'model_id', 'model_type']);
```

MySQL and MariaDB permit multiple `NULL` values inside a unique key. Applications requiring exactly one global-team Role per name should use a non-null sentinel or a database-appropriate normalized/generated uniqueness key.

<a name="partition-cache-and-performance"></a>
### Partition Cache and Performance

The resolved partition is part of every catalog, model-assignment, assignment-token, wildcard, via-role, and coroutine-local cache identity. Built-in mutations invalidate only the affected partition and, where possible, only the affected subject/team entry. Changing a Role in workspace A does not clear workspace B's catalog or assignment token.

`permission:cache-reset` and `PermissionRegistrar::forgetCachedPermissions()` clear only the ambient partition when partitioning is enabled. Missing context throws. Permission does not provide a global partition enumerator; cross-partition maintenance should enumerate the application's own partition domain, establish each context, and invoke the normal reset.

Partitioning adds no database queries to authorization or normal mutation paths:

- warm authorization checks remain zero-query;
- a cold permission catalog remains three queries;
- cold authorization and assignment-cache misses retain their unpartitioned query counts;
- synchronization uses the same query count in partitioned and unpartitioned modes; Role sync uses one delete and one bulk insert, while direct-permission sync adds the pivot read needed to compare forbidden effects;
- the resolver is an in-memory Context lookup;
- existing SQL receives one bound partition predicate;
- pivot inserts receive the partition value.

With partition-leading indexes, the added predicate narrows the rows each query examines. It does not introduce a join or discovery query on ordinary operations. Hard subject deletion is the deliberate cold-path exception: when partitioning and/or teams are enabled, each assignment-owning trait performs one narrow discovery query for its own table so it can forget the exact partition/team cache identities it deletes. A model using only `HasPermissions` therefore uses one discovery query; a model using `HasRoles` uses one for each of the role and direct-permission assignment tables. No discovery query runs when both features are disabled.

Role and Permission removal use one blind captured-scope delete regardless of listener presence because their public events report the already-known request. Role synchronization adds one pivot-only ID read only when `RoleDetachedEvent` has a listener, because that established payload is the pre-operation current Role set. The ordinary Role-sync path performs no discovery read.

Use your database's `EXPLAIN` command to confirm application indexes begin with the partition predicate used by the query, for example `workspace_id, name, guard_name` for name lookup or `workspace_id, model_type, model_id` for subject assignments. Optimizer plans differ by engine, so verify them against production data rather than relying on one fixed plan.

Logical cache keys include the raw canonical partition through collision-safe length-prefixed segments. Swoole cache stores hash logical keys before native table lookup, and Hypervel's Swoole table wrapper rejects oversized values instead of silently truncating them. Size Swoole cache table row count and value capacity for the application's number of partitions and catalog size.

<a name="raw-and-bulk-writes"></a>
### Raw and Bulk Writes

Package model and relation APIs apply partition predicates, invariant pivot values, and cache invalidation automatically. Package-owned multi-write operations such as synchronization and deferred multi-context flushing are transactional. Simple assignment and removal methods retain native Eloquent attach/detach semantics; wrap them in an application transaction when they must commit atomically with model touches or other application work. Low-level database APIs intentionally bypass some or all of those guarantees.

Direct Query Builder writes, generic Pivot saves, `toBase()`, `getQuery()`, `newQueryWithoutScopes()`, explicit scope removal, truncation, insert-from-select, and builder force deletes require the application to supply the correct partition predicate/value, use an appropriate transaction, and reset every affected partition cache.

Static Eloquent passthrough writes such as `insert`, `insertOrIgnore`, `insertGetId`, and `upsert` do not instantiate models and bypass partition insertion checks. Builder `update`, `increment`, and `decrement` retain the global partition predicate but bypass model lifecycle invalidation and must not change the partition column. Reset the affected ambient partition after any raw or bulk mutation.

<a name="teams"></a>
## Teams

Teams scope roles and role or permission assignments by a configured team foreign key. Enable teams before running the base permission migration if you want the base tables to include team columns:

```php
'teams' => true,
'models' => [
    'team' => App\Models\Team::class,
],
```

Use the helpers to set the current team for the current coroutine:

```php
setPermissionsTeamId($team->getKey());

$user->assignRole('writer');
```

You may also pass a team model:

```php
setPermissionsTeamId($team);
```

Roles may be global or team-specific:

```php
Role::create(['name' => 'writer', 'team_id' => null]);

Role::create(['name' => 'writer', 'team_id' => $team->getKey()]);
```

If teams are enabled after the package tables already exist, run `permission:setup-teams` and then migrate.

When you change the active team during a request or job, package-loaded permission relations are reloaded automatically when their stored provenance no longer matches the active team:

```php
setPermissionsTeamId($newTeamId);

$user->hasRole('writer');
```

<a name="wildcard-permissions"></a>
## Wildcard Permissions

Wildcard permissions allow one stored permission to match many checks:

```php
'enable_wildcard_permission' => true,
```

```php
Permission::create(['name' => 'posts.*']);

$user->givePermissionTo('posts.*');

$user->hasPermissionTo('posts.create');
// true
```

A wildcard permission string is split into dot-separated parts. The `*` part means all values for that part, not any permission in the system:

```php
Permission::create(['name' => 'posts.*']);

$user->givePermissionTo('posts.*');
```

Subparts may be comma-separated:

```php
Permission::create(['name' => 'posts,users.create,update,view']);

$user->givePermissionTo('posts,users.create,update,view');
```

The wildcard permission or wildcard pattern must exist as a permission record before it can be assigned or checked.

<a name="polymorphic-models"></a>
## Polymorphic Models

Roles and permissions use polymorphic relationships, so any Eloquent model may receive them:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Traits\HasRoles;

class Team extends Model
{
    use HasRoles;
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

In an unpartitioned application, models that replace rather than extend the package bases must implement `Hypervel\Permission\Contracts\Role` or `Hypervel\Permission\Contracts\Permission`. Partition-enabled Role and Permission models must extend the package bases so every unscoped Eloquent lifecycle path remains protected.

The package's default role and permission models do not use soft deletes, and soft deletes are not recommended for permission models. Roles and permissions are access-control records; deleting one should normally remove its assignments, not leave them waiting to become active again later.

If you use a custom role or permission model that uses `SoftDeletes`, soft-deleting a role or permission hides it from normal permission checks, but its assignment rows remain in the database. If the role or permission is restored, those assignments become active again. For roles, previous user-role and role-permission assignments become active again. For permissions, previous direct model-permission and role-permission assignments become active again.

Use hard deletes for roles and permissions when assignments should be removed permanently.

<a name="uuid-and-ulid-keys"></a>
## UUID and ULID Keys

If your user models use UUIDs or ULIDs, update the published migration before running it so `model_has_roles` and `model_has_permissions` use the correct morph key column type:

```php
$table->uuidMorphs('model');
```

If your role or permission models use UUIDs or ULIDs, extend the package models and set the primary key details on your custom models:

```php
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    use HasUuids;

    protected string $primaryKey = 'uuid';
}
```

Then update the published migration so the `roles`, `permissions`, and pivot tables use the same key type and references. You may also rename the model morph key in config:

```php
'column_names' => [
    'model_morph_key' => 'model_uuid',
],
```

Integer, UUID, and ULID Role, Permission, partition, and subject keys are supported. Keep every foreign and pivot column's native type identical to the key it references. In a partitioned schema, include the native partition column on all five authorization tables and use composite `(partition, related_id)` foreign keys as shown in [Partitioned Schema](#partitioned-schema).

<a name="caching"></a>
## Caching

The permission registrar caches role and permission metadata using the configured cache store. Hot checks also use Hypervel's memo cache layer for the current coroutine, so repeated checks in one request or job avoid repeated cache-store reads.

By default, cache identities are application-wide. When [row partitioning](#row-partitioning) is enabled, every relevant shared and coroutine-local identity includes the current partition automatically. Cache namespacing alone is not row isolation; use `resolvePartitionUsing` so database queries, pivot writes, relations, commands, cache entries, and invalidation all share the same fail-closed boundary.

Built-in mutation methods refresh the relevant cache automatically:

```php
$role->givePermissionTo('edit articles');
$role->revokePermissionTo('edit articles');
$role->syncPermissions(['edit articles']);

$user->assignRole('writer');
$user->removeRole('writer');
$user->syncRoles(['writer']);

$user->givePermissionTo('edit articles');
$user->giveForbiddenTo('delete articles');
$user->syncPermissionsWithForbidden(
    allowed: ['edit articles'],
    forbidden: ['delete articles'],
);
```

Exact subject assignment mutations forget that subject's affected assignment entry. Role or Permission catalog mutations invalidate only the affected partition and advance only that partition's assignment token. Raw or bulk writes bypass lifecycle invalidation and require an explicit reset in each affected established partition.

You may clear cached permission data with the command:

```shell
php artisan permission:cache-reset
```

When partitioning is enabled, this clears only the ambient partition and throws if context is missing.

You may also clear it from code:

```php
use Hypervel\Permission\PermissionRegistrar;

app(PermissionRegistrar::class)->forgetCachedPermissions();
```

<a name="testing-and-seeding"></a>
## Testing and Seeding

Partition-enabled tests and seeders must establish application partition context before resolving Role or Permission models, assigning authorization data, or clearing caches:

```php
use Hypervel\Support\Facades\Context;

Context::add('workspace_id', $workspace->getKey());
```

Do not disable partitioning or fall back to an unpartitioned cache during tests. Use the same context path as production so missing-context and isolation failures remain visible.

If tests create roles or permissions after the Gate has already registered its permission callback, clear the package cache in the test setup:

```php
use Hypervel\Permission\PermissionRegistrar;

protected function setUp(): void
{
    parent::setUp();

    $this->app->make(PermissionRegistrar::class)->forgetCachedPermissions();
}
```

Seeders that create roles and permissions should clear the cache before seeding. If your seeder disables model events, clear it again after creating roles and permissions and before assigning them:

```php
use Hypervel\Database\Seeder;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Context;

class RolesAndPermissionsSeeder extends Seeder
{
    private const string WORKSPACE_ID = '0198f311-7d47-7c41-962a-97a99d8638ef';

    public function run(): void
    {
        Context::add('workspace_id', self::WORKSPACE_ID);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::create(['name' => 'edit articles']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::create(['name' => 'writer'])
            ->givePermissionTo('edit articles');
    }
}
```

<a name="best-practices"></a>
## Best Practices

Use permissions for application behavior and roles for grouping permissions. For example, check `can('edit articles')` in controllers, policies, middleware, and Blade, then assign that permission to whichever roles should receive it.

Use direct role checks for role-management screens or rare app rules that truly depend on the role itself:

```php
if ($user->hasRole('admin')) {
    // ...
}
```

Prefer policies and Gate checks when authorization depends on both the user and a specific model instance.

<a name="performance"></a>
## Performance

Permission checks use cached role and permission data after the first lookup. Model role assignments and direct permission assignments have their own cache keys. Those keys include the model type, model key, active partition when enabled, active team when team-scoped, and the partition-specific assignment token.

Warm authorization checks execute no database queries. A cold catalog uses three queries. Enabling row partitioning does not add queries: it adds one bound, indexed predicate to existing SQL and one value to pivot inserts. The partition resolver is an in-memory Context lookup. Exact subject mutations forget exact cache identities, while catalog-wide changes advance only the affected partition's assignment token so older entries expire naturally through the configured TTL.

If you need to display a model's roles or permissions, eager load the relationships you will render:

```php
$users = User::with(['roles.permissions', 'permissions'])->get();
```

Eager loading is not required for normal `hasPermissionTo` or `hasRole` checks, since those checks use the package cache.

<a name="exceptions"></a>
## Exceptions

Authorization failures throw `Hypervel\Permission\Exceptions\UnauthorizedException`. You may handle it with Hypervel's normal exception handling:

```php
use Hypervel\Permission\Exceptions\UnauthorizedException;

$exceptions->render(function (UnauthorizedException $exception) {
    return response()->json([
        'message' => 'You do not have the required authorization.',
    ], 403);
});
```

The exception exposes the required roles or permissions:

```php
$exception->getRequiredRoles();

$exception->getRequiredPermissions();
```

Partition registration and isolation failures use focused exceptions:

- `PermissionPartitionAlreadyConfigured` when registration is repeated or occurs after registrar initialization;
- `PermissionPartitionNotResolved` when enabled partition context is missing;
- `PermissionPartitionViolation` when a model or pivot conflicts with its captured partition, attempts to change an immutable partition, or lacks a valid persisted partition value;
- `PermissionPartitionModelNotSupported` when partition mode is configured with a Role or Permission model that does not extend the package base.

<a name="differences-from-spatie-laravel-permission"></a>
## Differences From Spatie Laravel Permission

- Hypervel adds forbidden permissions. A forbidden permission explicitly denies an ability and wins over direct or role-granted allows. The deny flag is stored as the effect on the assignment row, so assigning allow or deny for the same model or role and permission updates the existing edge.
- `getDirectPermissions()`, `getPermissionsViaRoles()`, `getAllPermissions()`, and `getPermissionNames()` return effective allowed permissions. Explicit denies are exposed through `hasForbiddenPermission()` and `hasForbiddenPermissionViaRoles()`.
- Hypervel accepts pure unit enums anywhere enum names are valid role or permission inputs. Backed enums use their values; unit enums use their case names.
- Hypervel adds opt-in generic row partitioning through `PermissionRegistrar::resolvePartitionUsing(...)`. It scopes model lifecycle operations, every package relation and pivot, queries, commands, cache identities, and invalidation without depending on any partition domain.
- Hypervel's cache config uses `expiration_seconds` and separate named cache keys so role, model-role, model-permission, and assignment-token caches can be invalidated independently.
- Undefined `permission.cache.store` values fail fast through Hypervel's cache manager instead of silently falling back to an array store.
