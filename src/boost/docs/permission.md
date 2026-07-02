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

When you change the active team during a request or job, unset loaded permission relations before checking that same model again:

```php
setPermissionsTeamId($newTeamId);

$user->unsetRelation('roles')->unsetRelation('permissions');

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

If you replace the package models instead of extending them, your role model must implement `Hypervel\Permission\Contracts\Role` and your permission model must implement `Hypervel\Permission\Contracts\Permission`.

<a name="uuid-and-ulid-keys"></a>
## UUID and ULID Keys

If your user models use UUIDs or ULIDs, update the published migration before running it so `model_has_roles` and `model_has_permissions` use the correct morph key column type:

```php
$table->uuid($columnNames['model_morph_key']);
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

<a name="caching"></a>
## Caching

The permission registrar caches role and permission metadata using the configured cache store. Hot checks also use Hypervel's memo cache layer for the current coroutine, so repeated checks in one request or job avoid repeated cache-store reads.

By default, the cache is app-wide. If the same role or permission data can resolve to different records depending on request context, such as in a multi-tenant application with tenant-scoped permission tables, register a cache key resolver in a service provider:

```php
use Hypervel\Permission\PermissionRegistrar;

public function boot(): void
{
    PermissionRegistrar::resolveCacheKeyUsing(
        fn (): string => 'tenant:' . tenantId(),
    );
}
```

The resolver adds a context segment to the global permission catalog, model assignment caches, assignment-cache token, and wildcard permission indexes. Since the resolver is called during each cache-key build, it can safely read request-specific coroutine context. Teams still scope inside this context, so a multi-tenant app can have independent teams for each tenant.

**Why a static callback, not a config closure?** Config files are evaluated once at boot in Swoole. A closure calling `tenantId()` in config would capture the boot-time tenant (likely null), not the per-request tenant. The static resolver callback runs fresh when permission cache keys are built, reading the current coroutine's context.

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

If you change permission tables directly with queries, reset the permission cache yourself.

You may clear cached permission data with the command:

```shell
php artisan permission:cache-reset
```

You may also clear it from code:

```php
use Hypervel\Permission\PermissionRegistrar;

app(PermissionRegistrar::class)->forgetCachedPermissions();
```

<a name="testing-and-seeding"></a>
## Testing and Seeding

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

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
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

Permission checks use cached role and permission data after the first lookup. Model role assignments and direct permission assignments have their own cache keys, and those keys include the model type, model key, active team id when teams are enabled, assignment-cache token, and the custom cache-key scope when one is registered.

When roles, permissions, or assignment-wide state changes, the package writes a new assignment-cache token so older per-model cache keys are bypassed and expire naturally through the configured cache TTL.

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

<a name="differences-from-spatie-laravel-permission"></a>
## Differences From Spatie Laravel Permission

- Hypervel adds forbidden permissions. A forbidden permission explicitly denies an ability and wins over direct or role-granted allows. The deny flag is stored as the effect on the assignment row, so assigning allow or deny for the same model or role and permission updates the existing edge.
- Hypervel accepts pure unit enums anywhere enum names are valid role or permission inputs. Backed enums use their values; unit enums use their case names.
- Hypervel's cache config uses `expiration_seconds` and separate named cache keys so role, model-role, model-permission, and assignment-token caches can be invalidated independently.
