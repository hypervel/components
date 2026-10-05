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
    - [Denied Permissions](#denied-permissions)
    - [Revoking Permissions](#revoking-permissions)
    - [Retrieving Permissions](#retrieving-permissions)
- [Using Enums](#using-enums)
- [Middleware](#middleware)
    - [Permission Middleware](#permission-middleware)
    - [Role Middleware](#role-middleware)
    - [Role Or Permission Middleware](#role-or-permission-middleware)
    - [Middleware Aliases](#middleware-aliases)
    - [Controller Middleware](#controller-middleware)
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
    - [Setting the Current Team](#setting-the-current-team)
    - [Team Roles](#team-roles)
    - [Switching Teams](#switching-teams)
- [Wildcard Permissions](#wildcard-permissions)
- [Polymorphic Models](#polymorphic-models)
- [Custom Models](#custom-models)
    - [Adding Columns](#adding-columns)
    - [Permission Database Connection](#permission-database-connection)
    - [Custom Pivot Models](#custom-pivot-models)
    - [Deleting Models](#deleting-models)
- [UUID and ULID Keys](#uuid-and-ulid-keys)
- [Caching](#caching)
- [Testing and Seeding](#testing-and-seeding)
    - [Seeding](#seeding)
    - [Testing](#testing)
- [Best Practices](#best-practices)
- [Performance](#performance)
- [Exceptions](#exceptions)
- [Credits](#credits)

<a name="introduction"></a>
## Introduction

Hypervel's permission package provides role-based access control for Eloquent models. A permission represents one ability, such as `edit articles`. A role is a named group of permissions, such as `editor`. You may assign roles and permissions to users or other models, then check access by role, direct permission, or permission inherited through a role:

```php
$user->givePermissionTo('edit articles');

$role->givePermissionTo('edit articles');

$user->assignRole('writer');
```

Permissions are registered with Hypervel's [authorization gate](/docs/{{version}}/authorization), so you may check them using `can`, `@can`, policies, and authorization middleware as usual:

```php
$user->can('edit articles');
```

The package also supports denied permissions, which explicitly reject an ability even when the model receives the same permission directly or through a role.

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

The `role_has_permissions` and `model_has_permissions` tables include an `is_denied` column used by [denied permissions](#denied-permissions).

The migration reads its table and column names from the permission configuration file, so make these decisions before running it:

- Customize the [table and column names](#table-and-column-names) in the configuration file.
- Enable [teams](#teams) if you plan to use them, so the migration adds the team columns.
- Change the key column types in the published migration if your models use [UUID or ULID keys](#uuid-and-ulid-keys).
- Replace the migration with a [partitioned schema](#partitioned-schema) if you will use row partitioning.

The default migration makes `name` and `guard_name` unique together, so the same name may be used by different guards.

<a name="configuration"></a>
## Configuration

<a name="models"></a>
### Models

You may customize the models used for roles and permissions:

```php
'models' => [
    'permission' => App\Models\Permission::class,
    'role' => App\Models\Role::class,
    'team' => App\Models\Team::class,
    'default_model' => null,
],
```

Custom role models must implement the `Hypervel\Permission\Contracts\Role` contract. Custom permission models must implement the `Hypervel\Permission\Contracts\Permission` contract. The easiest way to satisfy these contracts is to [extend the package's base models](#custom-models).

The `team` model is used by the [teams](#teams) feature and may be `null` when teams are disabled. The `default_model` is used when you pass raw IDs to a role's [`assignToModels`](#assigning-models-to-a-role) method and similar methods; when it is `null`, the user model of the role's guard is used.

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

The role and permission pivot keys may be omitted or set to `null` to use `role_id` and `permission_id`. Omitting the team foreign key uses `team_id`. Model, table, and morph-key settings remain required because they define the package schema.

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

When `store` is omitted or set to `default`, the application's default cache store is used. A store that isn't defined in your cache configuration throws an exception. The expiration defaults to 24 hours when omitted. The separate keys hold the role and permission catalog, each model's roles, each model's direct permissions, and a token that versions the assignment caches, so a change only clears the data it affects. Omitted keys use the names shown in the example. The `column_names_except` list removes columns you do not need from the cached roles and permissions; their key, name, guard, team, and partition columns cannot be excluded.

You may include required role or permission names in authorization exception messages:

```php
'display_permission_in_exception' => true,
'display_role_in_exception' => true,
```

These options only change the text of `UnauthorizedException` messages. They do not change authorization results or the required names available through the exception's accessors. Keep them disabled when role or permission names are sensitive.

<a name="model-setup"></a>
## Model Setup

To assign roles and permissions to a model, add the `Hypervel\Permission\Traits\HasRoles` trait:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasRoles;

    // ...
}
```

The `HasRoles` trait includes the permission methods, so a model using `HasRoles` may receive roles and direct permissions. Checks such as `can` and `@can` also require the model to implement the `Hypervel\Contracts\Auth\Access\Authorizable` contract, which the base `Authenticatable` user class already does.

The trait defines `roles` and `permissions` relationships on your model. Your model should not have its own `role`, `roles`, `permission`, or `permissions` attributes, columns, methods, or relationships, since they would conflict with the trait.

<a name="multiple-guards"></a>
## Multiple Guards

Roles and permissions belong to a guard, so each guard has its own set of roles and permissions. When you create a role or permission without a `guard_name`, your application's default authentication guard is used. If your app uses multiple guards, create the role or permission for the guard that will authorize it:

```php
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;

Role::create(['name' => 'manager', 'guard_name' => 'admin']);

Permission::create(['name' => 'publish articles', 'guard_name' => 'admin']);

Permission::create(['name' => 'publish articles', 'guard_name' => 'web']);
```

You may pass the guard name when checking a permission or role:

```php
$user->hasPermissionTo('publish articles', 'admin');

$user->hasRole('manager', 'admin');
```

A model may only receive roles and permissions for its own guards. The package determines a model's guards from its `guardName` method, then its `$guard_name` property, then the configured guards whose user provider uses the model's class. Names are looked up for the model's default guard, and assigning a role or permission model that belongs to another guard throws a `GuardDoesNotMatch` exception.

When a model can use more than one guard, return each guard from the `guardName` method or `$guard_name` property:

```php
public function guardName(): array
{
    return ['web', 'admin'];
}
```

If your app uses one set of roles and permissions for every guard, return a single guard from the model so you do not need duplicate role and permission records:

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

$role->revokePermissionTo('delete articles');

$role->syncPermissions(['edit articles', 'publish articles']);

if ($role->hasPermissionTo('edit articles')) {
    // ...
}
```

You may also work from the permission side using the `assignRole`, `removeRole`, and `syncRoles` methods:

```php
$permission->assignRole($role);

$permission->removeRole($role);

$permission->syncRoles(['writer', 'editor']);
```

Models with a role receive its permissions automatically. The role's `permissions` relationship returns every permission assigned to the role, including [denied permissions](#denied-permissions); each related model's `pivot->is_denied` attribute tells you which effect it has:

```php
$names = $role->permissions->pluck('name');

$count = $role->permissions->count();
```

To replace a role's allowed and denied permissions at the same time, use `syncPermissionEffects`:

```php
$role->syncPermissionEffects(
    allowed: ['edit articles'],
    denied: ['delete articles'],
);
```

<a name="working-with-roles"></a>
## Working With Roles

<a name="assigning-roles"></a>
### Assigning Roles

You may assign roles by name, ID, enum, or `Role` model, and pass several roles as separate arguments, an array, or a collection:

```php
$user->assignRole('writer');

$user->assignRole('writer', 'editor');

$user->assignRole(['writer', 'editor']);

$user->assignRole($writer->id);

$user->assignRole($writer);
```

Integers and UUID or ULID strings are looked up by key; other strings are role names.

To replace all of a model's roles, use `syncRoles`:

```php
$user->syncRoles('writer', 'editor');
```

<a name="assigning-models-to-a-role"></a>
### Assigning Models to a Role

Sometimes it is more convenient to work from the role's side, such as on an admin screen listing every user with a role. The `assignToModels`, `removeFromModels`, and `syncModels` methods do the inverse of `assignRole`, `removeRole`, and `syncRoles`:

```php
use Hypervel\Permission\Models\Role;

$role = Role::findByName('writer');

// Give the role to two users...
$role->assignToModels([$userA, $userB]);

// Remove it from one user...
$role->removeFromModels($userA);

// Replace every model that has this role...
$role->syncModels([$userB, $userC]);
```

These methods accept a model, an ID, or an array or collection mixing models and IDs. Models may be of any class using `HasRoles`. When you pass raw IDs, the package uses the class given as the second argument, then the `default_model` configuration value, then the user model of the role's guard:

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

// The model has at least one of the roles...
if ($user->hasRole(['editor', 'moderator'])) {
    // ...
}

if ($user->hasAnyRole('writer', 'reader')) {
    // ...
}

if ($user->hasAllRoles(['writer', 'editor'])) {
    // ...
}

// The model has these roles and no others...
if ($user->hasExactRoles(['writer', 'editor'])) {
    // ...
}
```

These methods also accept `Role` models, collections, and strings separated by `|`, such as `'writer|editor'`. The `hasRole`, `hasAllRoles`, and `hasExactRoles` methods accept a guard name as their second argument.

You may retrieve the names of a model's roles using `getRoleNames`:

```php
$roles = $user->getRoleNames();
```

Prefer [permission checks](#checking-permissions) for application behavior and keep role checks for the rare rules that depend on the role itself. See [best practices](#best-practices) for details.

<a name="role-and-team-scopes"></a>
### Role and Team Scopes

You may query models by assigned roles:

```php
$writers = User::role('writer')->get();

$usersWithoutWriterRole = User::withoutRole('writer')->get();

$managerCount = User::role('manager')->count();
```

The scopes accept role names, IDs, enums, `Role` models, arrays, and collections, with an optional guard name as the second argument. Since roles and permissions are Eloquent relationships, you may also use the usual Eloquent methods:

```php
$users = User::with('roles')->get();

$usersWithoutRoles = User::doesntHave('roles')->get();

$roleNames = Role::pluck('name');
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

if ($user->hasPermissionTo($permission->id, 'admin')) {
    // ...
}
```

You may pass a permission name, ID, enum, or `Permission` model, with an optional guard name as the second argument. Integers and UUID or ULID strings are looked up by key; other strings are permission names. The `hasPermissionTo` method throws a `PermissionDoesNotExist` exception when no matching permission exists, while `checkPermissionTo` returns `false` instead.

A direct permission is assigned to the model itself rather than through one of its roles. For example, if the `writer` role may edit articles and the user is also given permission to delete articles, only the second is a direct permission:

```php
$role->givePermissionTo('edit articles');

$user->assignRole('writer');
$user->givePermissionTo('delete articles');

$user->hasDirectPermission('delete articles'); // true
$user->hasDirectPermission('edit articles'); // false

$user->getPermissionsViaRoles()->contains('name', 'edit articles'); // true
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

The `permission` scope returns models that are allowed the permission directly or through a role and are not denied it, while `withoutPermission` returns the rest. The scopes accept permission names, IDs, enums, `Permission` models, arrays, and collections. They match assigned permissions exactly, so [wildcard permissions](#wildcard-permissions) apply to checks such as `hasPermissionTo` but not to these scopes.

<a name="gate-and-super-admins"></a>
### Gate and Super Admins

The package registers a Gate `before` check by default, so normal authorization calls work with permissions:

```php
if ($user->can('edit articles')) {
    // ...
}
```

To give a super-admin role every ability without assigning it every permission, register a Gate [`before` callback](/docs/{{version}}/authorization#intercepting-gate-checks) in the `boot` method of your application's `AppServiceProvider`:

```php
use App\Models\User;
use Hypervel\Support\Facades\Gate;

Gate::before(function (User $user, string $ability): ?bool {
    return $user->hasRole('super-admin') ? true : null;
});
```

The callback should return `null` rather than `false` for other users, since a `false` result denies the ability before policies and permissions are checked. If you only want super-admin access for some models, you may use a [policy's `before` method](/docs/{{version}}/authorization#policy-filters) instead.

You may also use a Gate `after` callback, which runs after the policies and permissions. Its result is only used when the gate, policies, and permissions returned `null`, so super-admins are still refused abilities your policies deny to everyone, such as writing a second review:

```php
Gate::after(function (User $user, string $ability): bool {
    return $user->hasRole('super-admin');
});
```

Direct package calls such as `hasPermissionTo`, `hasAnyPermission`, and `hasDirectPermission` do not pass through the gate, so super-admin callbacks do not apply to them. Use `can`, `canAny`, policies, the permission middleware, or Blade authorization checks when you want the gate to apply.

When [teams](#teams) are enabled, a global super-admin role still has to be assigned to the user within each team.

<a name="denied-permissions"></a>
### Denied Permissions

Denied permissions explicitly reject access. Each assignment row stores whether it allows or denies the permission in its `is_denied` column, so a model or role has at most one assignment for a given permission (per team, when teams are enabled).

Calling `denyPermissionTo` for an allowed permission changes that assignment to a deny. Calling `givePermissionTo` for a denied permission changes it back to an allow. A denied permission overrides an allowed permission, including permissions inherited through roles:

```php
$user->givePermissionTo('delete articles');

$user->denyPermissionTo('delete articles');

$user->hasPermissionTo('delete articles');

// false
```

You may check whether an explicit denied permission exists directly on the model or through its roles:

```php
if ($user->hasDeniedPermission('delete articles')) {
    // ...
}

if ($user->hasDeniedPermissionViaRoles('delete articles')) {
    // ...
}
```

To retrieve every permission the model is denied, directly or through its roles, use `getDeniedPermissions`:

```php
$deniedPermissions = $user->getDeniedPermissions();
```

Use `syncPermissionEffects` to replace allowed and denied direct permissions together. If a permission is present in both arrays, the denied permission wins:

```php
$user->syncPermissionEffects(
    allowed: ['view articles', 'edit articles'],
    denied: ['edit articles', 'delete articles'],
);
```

The method returns the IDs it `attached`, `detached`, and `updated`. When it is called before the model is saved, the assignments are written when the model is saved and the returned arrays are empty, since no rows have changed yet.

<a name="revoking-permissions"></a>
### Revoking Permissions

You may remove permissions from a model:

```php
$user->revokePermissionTo('edit articles');

$user->revokePermissionTo(['edit articles', 'delete articles']);
```

This removes the assignment whether it currently allows or denies the permission.

<a name="retrieving-permissions"></a>
### Retrieving Permissions

You may retrieve the permissions a model receives directly, through its roles, or both:

```php
// Permissions assigned directly to the model...
$permissions = $user->getDirectPermissions();

// Permissions inherited from the model's roles...
$permissions = $user->getPermissionsViaRoles();

// Direct and inherited permissions...
$permissions = $user->getAllPermissions();

// The names of the direct permissions...
$names = $user->getPermissionNames();
```

These methods return the permissions the model is allowed and leave out [denied permissions](#denied-permissions), which you may retrieve using `getDeniedPermissions`. The model's `permissions` relationship returns every direct assignment, including denied ones; each related model's `pivot->is_denied` attribute tells you which effect it has.

<a name="using-enums"></a>
## Using Enums

You may use enums in place of role and permission names. String-backed enums use their value as the name, while unit enums use their case name. Separate enums for roles and permissions are usually easier to manage:

```php
namespace App\Enums;

enum PermissionName: string
{
    case EditArticles = 'edit articles';
    case DeleteArticles = 'delete articles';
    case PublishArticles = 'publish articles';
}

enum RoleName: string
{
    case Writer = 'writer';
    case Editor = 'editor';
    case Admin = 'admin';
}
```

You may pass enum cases when creating and finding roles and permissions, and to the role and permission methods:

```php
use App\Enums\PermissionName;
use App\Enums\RoleName;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;

$role = Role::create(['name' => RoleName::Writer]);
$role = Role::findByName(RoleName::Writer);
$permission = Permission::findOrCreate(PermissionName::EditArticles);

$user->assignRole(RoleName::Writer);

$user->givePermissionTo(PermissionName::EditArticles);

if ($user->hasPermissionTo(PermissionName::EditArticles)) {
    // ...
}
```

The gate [accepts enum abilities](/docs/{{version}}/authorization#enum-abilities) too, so you may pass the same cases to `can` and `@can`:

```php
if ($user->can(PermissionName::EditArticles)) {
    // ...
}
```

Unit enums work the same way, using their case names:

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

Since permissions are registered with the gate, you may protect a route with a single permission using Hypervel's built-in [`can` middleware](/docs/{{version}}/authorization#via-middleware):

```php
use Hypervel\Auth\Middleware\Authorize;

Route::post('/articles', [ArticleController::class, 'store'])
    ->middleware('can:publish articles');

Route::post('/articles', [ArticleController::class, 'store'])
    ->middleware(Authorize::using('publish articles'));
```

The package also includes `PermissionMiddleware`, `RoleMiddleware`, and `RoleOrPermissionMiddleware` for checking several permissions or roles at once. They require the authenticated user model to use the `HasRoles` trait. When the user is not authenticated or lacks the required roles or permissions, they throw a `Hypervel\Permission\Exceptions\UnauthorizedException`, which renders a 403 response.

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

When multiple permissions are provided, the user only needs one of them. You may pass an array or a string separated by `|`, and a guard name as the second argument:

```php
Route::get('/posts/edit', [PostController::class, 'edit'])
    ->middleware(PermissionMiddleware::using(['edit articles', 'edit all articles']));

Route::get('/api/posts/edit', [PostController::class, 'edit'])
    ->middleware(PermissionMiddleware::using('edit articles|edit all articles', 'api'));
```

The permission middleware checks each permission through the gate, so [super-admin callbacks](#gate-and-super-admins) apply to it.

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

Middleware may also receive [enum](#using-enums) cases:

```php
Route::get('/admin', [AdminController::class, 'index'])
    ->middleware(PermissionMiddleware::using(PermissionName::EditArticles));

Route::get('/editor', [EditorController::class, 'index'])
    ->middleware(RoleMiddleware::using([RoleName::Editor, RoleName::Admin]));
```

<a name="role-or-permission-middleware"></a>
### Role Or Permission Middleware

Use `RoleOrPermissionMiddleware::using` when the user may pass with either a role or a permission:

```php
use Hypervel\Permission\Middleware\RoleOrPermissionMiddleware;

Route::get('/content', [ContentController::class, 'index'])
    ->middleware(RoleOrPermissionMiddleware::using(['editor', 'edit articles']));
```

<a name="middleware-aliases"></a>
### Middleware Aliases

The package registers the `role`, `permission`, and `role_or_permission` middleware aliases for you. Separate several names with `|`, and add a guard name after a comma:

```php
Route::middleware('role:manager')->group(function () {
    // ...
});

Route::get('/articles/create', [ArticleController::class, 'create'])
    ->middleware('permission:publish articles|edit articles');

Route::get('/articles/{article}', [ArticleController::class, 'show'])
    ->middleware('role_or_permission:manager|edit articles');

Route::get('/api/admin', [AdminController::class, 'index'])
    ->middleware('role:manager,api');
```

If a route returns a 404 response where you expect a 403, route model binding may be running before the permission check. You may [sort the middleware](/docs/{{version}}/middleware#sorting-middleware) so the package's middleware runs before `SubstituteBindings`:

```php
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Permission\Middleware\PermissionMiddleware;
use Hypervel\Routing\Middleware\SubstituteBindings;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->prependToPriorityList(
        before: SubstituteBindings::class,
        prepend: PermissionMiddleware::class,
    );
})
```

<a name="controller-middleware"></a>
### Controller Middleware

You may also apply the middleware in a controller's [`middleware` method](/docs/{{version}}/controllers#controller-middleware) or with the [`Middleware` and `Authorize` attributes](/docs/{{version}}/controllers#middleware-attributes):

```php
use Hypervel\Routing\Attributes\Controllers\Authorize;
use Hypervel\Routing\Attributes\Controllers\Middleware;

#[Middleware('role:manager', except: ['show'])]
class ArticleController
{
    public function show()
    {
        // ...
    }

    #[Authorize('publish articles')]
    public function store()
    {
        // ...
    }
}
```

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

The client's `$guard_name` property or `guardName` method should return the name of your Passport guard. Set the client model in Passport, then protect client-credentials routes with this package's middleware. When the request has a bearer token and no authenticated user, the role, permission, and role-or-permission middleware authorize the client of the first guard using the `passport` driver.

<a name="blade-directives"></a>
## Blade Directives

Since permissions are registered with the gate, you may check them using Hypervel's `@can`, `@cannot`, and `@canany` directives. To check a permission for a specific guard, pass the guard name as the second argument:

```blade
@can('edit articles')
    ...
@endcan

@can('edit articles', 'admin')
    ...
@endcan
```

The package also registers a `@haspermission` directive, which checks the permission without the gate. There is no `@hasanypermission` directive; use `@canany` instead.

Although [permission checks are preferred](#best-practices), the package also provides directives for checking the authenticated user's roles:

```blade
@role('writer')
    I am a writer!
@else
    I am not a writer...
@endrole

@hasanyrole('writer|admin')
    I am a writer, an admin, or both!
@endhasanyrole

@hasallroles(['writer', 'admin'])
    I am both a writer and an admin!
@endhasallroles

@hasexactroles('writer|admin')
    I am a writer and an admin, and have no other roles!
@endhasexactroles

@unlessrole('guest')
    I am not a guest...
@endunlessrole
```

The `@hasrole` directive is an alias of `@role`. Role directives accept a role name, an array, a collection, or names separated by `|`. To check the user of a specific authentication guard, pass the guard name as the second argument:

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

By default, the package registers a Gate `before` callback that checks each ability with the user's `checkPermissionTo` method. The callback returns `true` when the user has the permission and `null` otherwise, so your policies and other gate callbacks still decide the remaining abilities:

```php
'register_permission_check_method' => true,
```

Set this to `false` only when you want to replace that check with your own logic. For example, if your application issues access tokens that carry the user's permissions, you might check the token instead of the database:

```php
'register_permission_check_method' => false,
```

```php
use App\Models\User;
use Hypervel\Support\Facades\Gate;

Gate::before(function (User $user, string $ability): ?bool {
    return $user->hasTokenPermission($ability) ?: null;
});
```

Here, `hasTokenPermission` is a method you would implement on your own model.

<a name="events"></a>
## Events

Role and permission assignment events are disabled by default. Enable them in the permission configuration file when your app listens for assignment changes:

```php
'events_enabled' => true,
```

The package dispatches the following events. Each receives the affected `$model`, along with the roles or permissions involved:

<div class="overflow-auto">

| Event | Roles or Permissions Property |
| --- | --- |
| `Hypervel\Permission\Events\RoleAttachedEvent` | `$rolesOrIds` |
| `Hypervel\Permission\Events\RoleDetachedEvent` | `$rolesOrIds` |
| `Hypervel\Permission\Events\PermissionAttachedEvent` | `$permissionsOrIds` |
| `Hypervel\Permission\Events\PermissionDetachedEvent` | `$permissionsOrIds` |

</div>

Events are only dispatched when they are enabled and have a listener:

```php
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Support\Facades\Event;

Event::listen(function (RoleAttachedEvent $event) {
    $user = $event->model;
    $roleIds = $event->rolesOrIds;
});
```

The roles or permissions may be IDs, a model, or an array or collection of either, so inspect the value before acting on it:

- Assigning, removing, and syncing roles, and giving or denying permissions, pass the requested IDs, even when nothing changed.
- Revoking a permission passes the `Permission` model or models.
- Syncing roles or permissions first dispatches a detached event with the model's previous role IDs or `Permission` collection, followed by an attached event with the requested IDs. The detached event is skipped when the model had none.

Events are dispatched after the database changes succeed, so a failed sync dispatches nothing. When you assign roles or permissions to a model that has not been saved yet, they are written when the model is saved, but their events are dispatched right away. A sync on an unsaved model only dispatches the attached event.

<a name="console-commands"></a>
## Console Commands

You may view the permission matrix using the `permission:show` command:

```shell
php artisan permission:show
```

Each cell shows `✔` when the role is allowed the permission, `✘` when the role is denied it, and `·` when the role has no assignment for it.

You may limit the output to a specific guard:

```shell
php artisan permission:show web
```

The command supports the `default`, `borderless`, `compact`, and `box` table styles:

```shell
php artisan permission:show web compact
```

You may create roles and permissions from the console, optionally passing a guard name as the second argument:

```shell
php artisan permission:create-role writer

php artisan permission:create-permission "edit articles"

php artisan permission:create-permission "edit articles" web
```

When creating a role, you may also create and assign permissions by listing them, separated by `|`. When teams are enabled, the `--team-id` option sets the role's team:

```shell
php artisan permission:create-role writer web "create articles|edit articles"

php artisan permission:create-role writer web --team-id=1
```

The `permission:assign-role` command assigns a role to a user, given the role name, the user's ID, and optionally the guard name and user model class:

```shell
php artisan permission:assign-role writer 1 web "App\Models\User"
```

The `permission:cache-reset` command [clears the permission cache](#caching), and `permission:setup-teams` creates a migration that adds the [team](#teams) columns to existing tables.

When row partitioning is enabled, the commands that read or write permission data run within the application's current partition and throw an exception when none is set, so set the [partition context](#partition-context) before running them. The `permission:setup-teams` command only creates a migration and does not need a partition.

<a name="row-partitioning"></a>
## Row Partitioning

Row partitioning keeps the roles, permissions, and assignments of separate workspaces, organizations, or tenants apart while storing them in the same tables. It is useful when the same user may have different roles and permissions in each workspace.

You tell the package which column holds the partition and how to read the current partition value. The package then applies that value to every role and permission query, model write, relationship, assignment, query scope, wildcard check, console command, queued model, and cache entry. Your application owns the partitions themselves: the package does not provide a partition model, middleware, command option, migration, or context storage.

Partitioning is opt-in. Without registration, the package keeps its normal behavior and schema.

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

The registration applies for the life of the worker, so register it once while your application boots rather than in a configuration file. The resolver runs whenever the package queries or caches permission data, so it should read a value your application has already placed in the current request's, job's, or command's [context](/docs/{{version}}/context) rather than query the database.

The column must be a simple SQL identifier. The resolver may return an integer, a non-empty string, or `null`; `0` and `'0'` are valid values. Always use the same representation for a given partition. When the resolver returns `null` or an empty string, the package throws a `PermissionPartitionNotResolved` exception instead of running unpartitioned queries.

<a name="partitioned-schema"></a>
### Partitioned Schema

The package's migration is not partitioned. If you use partitioning, replace it with your own migration that adds the same non-null partition column to all five tables:

- `roles`
- `permissions`
- `role_has_permissions`
- `model_has_roles`
- `model_has_permissions`

The following example uses UUIDs for the partition, role, permission, and user keys. Use integer, UUID, or ULID columns to match your own keys:

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
    $table->boolean('is_denied')->default(false);

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
    $table->string('model_type');
    $table->uuid('model_id');

    $table->primary(['workspace_id', 'role_id', 'model_id', 'model_type']);
    $table->index(
        ['workspace_id', 'model_type', 'model_id'],
        'model_has_roles_partition_subject_index',
    );

    $table->foreign(['workspace_id', 'role_id'])
        ->references(['workspace_id', 'id'])
        ->on('roles')
        ->cascadeOnDelete();
});

Schema::create('model_has_permissions', function (Blueprint $table): void {
    $table->uuid('workspace_id');
    $table->uuid('permission_id');
    $table->string('model_type');
    $table->uuid('model_id');
    $table->boolean('is_denied')->default(false);

    $table->primary(['workspace_id', 'permission_id', 'model_id', 'model_type']);
    $table->index(
        ['workspace_id', 'model_type', 'model_id'],
        'model_has_permissions_partition_subject_index',
    );

    $table->foreign(['workspace_id', 'permission_id'])
        ->references(['workspace_id', 'id'])
        ->on('permissions')
        ->cascadeOnDelete();
});
```

The composite foreign keys reference the `['workspace_id', 'id']` unique keys, so the database rejects any assignment that links records from different partitions. The user lookup indexes have explicit names because generated names can exceed the identifier length MySQL and MariaDB allow. You may also add a foreign key from `workspace_id` to your own workspace table.

Keep the partition column first in each primary key, unique key, and index, since every query the package runs filters by it.

Each `model_type` and `model_id` pair must identify one model across all partitions. UUIDs and ULIDs are the simplest choice, and integer IDs that are unique across partitions also work. Integer IDs that repeat between partitions for different records are not supported, since deleting a model removes its assignments in every partition.

When partitioning is enabled, custom role and permission models must extend the package's models. UUID models may use the `HasUuids` trait as usual:

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

Configure both classes under `permission.models`. The package's models keep the partition check on Eloquent operations that skip global scopes, such as instance updates, deletes, refreshes, quiet operations, increments, and restoring queued models.

<a name="partition-context"></a>
### Partition Context

Set the current partition before authenticating the user or running any permission operation. Typically, a middleware finds the request's workspace and stores its key in `Context`, then the request authenticates and authorizes as usual:

```php
use Hypervel\Support\Facades\Context;

Context::add('workspace_id', $workspace->getKey());
```

Hypervel passes `Context` values to queued jobs and restores them before the job's models are restored, so a job dispatched within a workspace runs in that workspace. Console commands, scheduled tasks, and seeders must set the context themselves before using roles and permissions, running the package's commands, or clearing the cache.

Roles and permissions always belong to a partition. A user model that is not partitioned may have different roles and permissions in each partition. If the user model has its own partition column, the package rejects assignments when its value differs from the current partition.

The package writes the current partition to each assignment row. Pivot data you pass may leave out the partition column or repeat the current value, but a different value throws an exception, and pivot updates cannot move an assignment to another partition.

When you load roles or permissions using `select()`, include the partition column if you will use their relationships or later refresh, save, restore, or delete them.

<a name="partitions-teams-and-guards"></a>
### Partitions, Teams, and Guards

Partitions, teams, and guards are separate, and the package filters by each of them:

```sql
where workspace_id = ?
  and team_id = ?
  and guard_name = ?
```

A partition is not a [team](#teams). You may use partitions without teams, or many teams within each partition. When teams are enabled, put the team column after the partition column in the keys:

```php
$table->unique(['workspace_id', 'team_id', 'name', 'guard_name']);
$table->primary(['workspace_id', 'team_id', 'role_id', 'model_id', 'model_type']);
$table->primary(['workspace_id', 'team_id', 'permission_id', 'model_id', 'model_type']);
```

The `roles` table's team column is nullable, since global roles have no team. The assignment tables' team columns are not nullable: every assignment stores the current team, even for a global role, so they can be part of the primary keys above.

All supported databases allow several `NULL` values in a unique key, so the unique key above does not stop two global roles from having the same name. If you need that guarantee, add a unique index over a generated column that replaces a `NULL` team with a fixed value.

<a name="partition-cache-and-performance"></a>
### Partition Cache and Performance

The current partition is part of every cache key the package uses, so each partition has its own cached roles, permissions, assignments, and wildcard indexes. The package's own changes only clear the cache entries of the partition they affect: changing a role in workspace A does not clear workspace B's cache.

The `permission:cache-reset` command and `forgetCachedPermissions` method clear only the current partition and throw an exception when no partition is set. To reset every partition, loop over your own workspaces, set each one's context, and reset its cache.

When a custom migration recreates partitioned tables, reset each affected partition's cache this way. Otherwise, cached assignments from the old tables may apply to new records that reuse their keys.

Partitioning adds no queries to permission checks or assignments. Each query gains one partition condition, and each assignment row stores the partition value. Warm permission checks run no queries, and loading the role and permission catalog takes three queries, the same as without partitioning.

Hard deleting a user model is the exception. When partitioning or teams are enabled, the package first reads which partitions and teams the model's assignments belong to, so it can clear exactly those cache entries. This adds one query for each assignment table the model uses.

Use your database's `EXPLAIN` command to check that your indexes start with the partition column each query filters by, such as `workspace_id, name, guard_name` for finding roles by name or `workspace_id, model_type, model_id` for loading a user's assignments. Query plans differ between databases, so check them against production data.

If you use the Swoole cache store, size its table for your number of partitions and the amount of role and permission data in each. The store rejects values larger than its configured size instead of truncating them.

<a name="raw-and-bulk-writes"></a>
### Raw and Bulk Writes

The package's models and methods add the partition condition, write the partition value, and clear the affected cache for you. Methods that make several writes, such as syncing, run in a transaction. Single assignment and removal methods behave like Eloquent's `attach` and `detach`, so wrap them in a transaction when they must commit together with other work.

Lower-level database APIs skip some or all of this. When you use the query builder directly, save a generic pivot, call `toBase`, `getQuery`, or `newQueryWithoutScopes`, remove the partition scope, truncate a table, insert from a select, or force delete through a query, you must add the partition condition or value yourself, use a transaction where needed, and reset the cache of each affected partition.

Eloquent's `insert`, `insertOrIgnore`, `insertGetId`, and `upsert` methods do not create models, so they skip the partition checks. Query `update`, `increment`, and `decrement` calls keep the partition condition but skip cache clearing, and must not change the partition column. Reset the current partition's cache after any of these writes.

<a name="teams"></a>
## Teams

Teams let a model have different roles and permissions in each team it belongs to, such as an organization or project. Roles may be global or belong to one team, and every role or permission assignment belongs to a team.

Enable teams in the permission configuration file before running the package's migration, so it adds the team columns. If the tables already exist, run the `permission:setup-teams` command and then migrate:

```php
'teams' => true,

'models' => [
    'team' => App\Models\Team::class,
],
```

The migration creates integer team columns named `team_id`. You may rename them using the `column_names.team_foreign_key` configuration value, and change their type in the published migration if your team keys are UUIDs or ULIDs.

<a name="setting-the-current-team"></a>
### Setting the Current Team

The package checks and changes roles and permissions within the current team. Set the current team at the start of each request, typically in a middleware:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionsTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        setPermissionsTeamId($request->session()->get('team_id'));

        return $next($request);
    }
}
```

Add the middleware to the `web` group, and [sort it](/docs/{{version}}/middleware#sorting-middleware) before `SubstituteBindings` so the team is set before route model binding and authorization middleware run:

```php
use App\Http\Middleware\SetPermissionsTeam;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Routing\Middleware\SubstituteBindings;

->withMiddleware(function (Middleware $middleware): void {
    $middleware->web(append: [
        SetPermissionsTeam::class,
    ]);

    $middleware->prependToPriorityList(
        before: SubstituteBindings::class,
        prepend: SetPermissionsTeam::class,
    );
})
```

The `setPermissionsTeamId` helper also accepts a team model, and `getPermissionsTeamId` returns the current team's key. The current team belongs to the current request or job only, so it never carries over to other requests. Queued jobs and new coroutines start without a team, so set it again within them.

Select the current team before changing a model's roles or direct permissions, including when assigning a global role. Without a current team, these methods throw a `TeamNotSelected` exception before touching the database.

By default, the current team is stored in the request's [coroutine context](/docs/{{version}}/coroutine-context). You may replace the `team_resolver` configuration value with a class that implements `Hypervel\Permission\Contracts\PermissionsTeamResolver`.

<a name="team-roles"></a>
### Team Roles

A role created without a `team_id` belongs to the current team:

```php
// A global role, which may be assigned within any team...
Role::create(['name' => 'writer', 'team_id' => null]);

// A role for one team; other teams may have roles with the same name...
Role::create(['name' => 'reader', 'team_id' => $team->getKey()]);

// A role for the current team...
Role::create(['name' => 'reviewer']);
```

Assigning and removing roles and permissions works the same as without teams, within the current team.

<a name="switching-teams"></a>
### Switching Teams

You may change the current team during a request or job, such as when a user switches teams or an admin page manages a user's roles in each team. The package's methods reload roles and permissions that were loaded for the previous team automatically:

```php
setPermissionsTeamId($newTeamId);

$user->hasRole('writer');
$user->can('edit articles');
```

Reading the `roles` or `permissions` relationship directly returns what was loaded earlier, like any Eloquent relationship. Unset a relationship loaded for the previous team before reading it:

```php
$roles = $user->unsetRelation('roles')->roles;
```

<a name="wildcard-permissions"></a>
## Wildcard Permissions

Wildcard permissions let one assigned permission match many checks. They are inspired by [Apache Shiro's permissions](https://shiro.apache.org/permissions.html). You may enable them in the permission configuration file:

```php
'enable_wildcard_permission' => true,
```

A wildcard permission is made of parts separated by dots, such as `posts.create.1`. The meaning of each part is up to your application. A common pattern is `{resource}.{action}.{target}`, but you may use as many parts as you like.

Any part may be `*`, which matches every value of that part:

```php
Permission::create(['name' => 'posts.*']);

$user->givePermissionTo('posts.*');

$user->hasPermissionTo('posts.create'); // true
$user->hasPermissionTo('posts.edit'); // true
```

A trailing `*` is implied, so assigning `posts` also grants `posts.create` and `posts.edit`. In a checked name, `*` means "all" rather than "any": checking `posts.*` passes when the user was given `posts.*` or `posts`, but not when they only have `posts.create`.

A part may also list several values separated by commas:

```php
// Create, update, and view posts and users...
$user->givePermissionTo('posts,users.create,update,view');

// Create, update, and view any resource...
$user->givePermissionTo('*.create,update,view');

// Do anything to the posts with IDs 1, 4, and 6...
$user->givePermissionTo('posts.*.1,4,6');
```

Like any permission, a wildcard permission must exist as a permission record before it can be assigned. The names you check do not need records of their own, so `hasPermissionTo('posts.create')` matches `posts.*` even when no `posts.create` permission exists.

[Denied permissions](#denied-permissions) use the same matching. A denied wildcard permission blocks every name it matches, and a denied permission blocks the names an allowed wildcard permission would otherwise grant:

```php
$user->givePermissionTo('posts.*');
$user->denyPermissionTo('posts.delete');

$user->hasPermissionTo('posts.edit');
// true

$user->hasPermissionTo('posts.delete.123');
// false
```

`hasDeniedPermission` and `hasDeniedPermissionViaRoles` still match only the exact permission.

To customize wildcard parsing, configure `wildcard_permission` with a class that implements `Hypervel\Permission\Contracts\Wildcard`. The class receives the model as its `record` constructor argument. Its `getIndex` and `getDeniedIndex` methods index the model's allowed and denied permissions, and `implies` checks a permission against an index. Extending `Hypervel\Permission\WildcardPermission` lets you change its `WILDCARD_TOKEN`, `PART_DELIMITER`, and `SUBPART_DELIMITER` constants while keeping its indexing.

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

Assignments are stored with the model's morph class, so a child model class has its own roles and permissions. If a child model should only use its parent's roles and permissions, you may return the parent's morph class from the child's `getMorphClass` method. The child then shares every assignment with the parent model of the same key:

```php
use Hypervel\Database\Eloquent\Relations\Relation;

class Admin extends User
{
    public function getMorphClass(): string
    {
        return (string) Relation::getMorphAlias(User::class);
    }
}
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

In the rare case that you replace the models instead of extending them, your models must implement the `Hypervel\Permission\Contracts\Role` and `Hypervel\Permission\Contracts\Permission` contracts. When [row partitioning](#row-partitioning) is enabled, custom models must extend the package's models.

If you override `findByName`, `findOrCreate`, or `create` on your model, you may use the `enum_value` helper to accept [enums](#using-enums) as names:

```php
use Hypervel\Permission\Contracts\Role as RoleContract;
use UnitEnum;

use function Hypervel\Support\enum_value;

public static function findByName(UnitEnum|string $name, ?string $guardName = null): RoleContract
{
    $name = enum_value($name);

    // ...
}
```

The package's models do not use soft deletes, and soft deletes are not recommended for roles and permissions. Deleting a role or permission should normally remove its assignments rather than leave them waiting to become active again. If a custom model uses `SoftDeletes`, soft deleting it hides it from permission checks but keeps its assignments, and restoring it makes them active again. Use hard deletes when assignments should be removed permanently.

<a name="adding-columns"></a>
### Adding Columns

You may add your own columns to the roles and permissions tables with a migration, just like any other table. For example, the package does not include a description column, but you may add one:

```php
Schema::table('permissions', function (Blueprint $table) {
    $table->string('description')->nullable();
});

Schema::table('roles', function (Blueprint $table) {
    $table->string('description')->nullable();
});
```

Roles and permissions are cached with all of their columns except those listed in the `cache.column_names_except` configuration value. You may add large columns to that list to keep the cache small, but models read from the cache, such as those returned by `findByName`, will not have those columns.

<a name="permission-database-connection"></a>
### Permission Database Connection

All five permission tables, including assignment writes, use the configured permission model's database connection. The configured role and permission models must use that same connection name. Role relationships are read through the role model's connection, while assignments are written and cache changes are applied through the permission model's connection, so different connection names would break transactions even when they point to the same database.

Your user models may use another connection. When they live in a separate database, the `users` relationships of roles and permissions, and the role and permission query scopes on your models, are unavailable, because they join the permission tables using the user model's connection.

<a name="custom-pivot-models"></a>
### Custom Pivot Models

You may use a custom pivot model when permission or role assignments need additional casts, timestamps, events, or other model behavior. Alias the package relationship, then apply your pivot model using Eloquent's `using` method:

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Permission\Traits\HasPermissions;

class User extends Authenticatable
{
    use HasPermissions {
        permissions as traitPermissions;
    }

    public function permissions(): BelongsToMany
    {
        return $this->traitPermissions()->using(CustomPermissionPivot::class);
    }
}
```

You may customize role assignments in the same way by aliasing the `roles` relationship and applying your custom role pivot model.

When a custom permission pivot is configured, `getDirectPermissions()` and `getAllPermissions()` load the model's relationship so the returned Permission models include your pivot class. Normal authorization checks and `getPermissionNames()` continue using the compact permission cache.

The reverse `assignToModels`, `removeFromModels`, and `syncModels` methods do not use the assigned model's relationship override. When your custom pivot behavior is required, perform the assignment through the model's `givePermissionTo`, `revokePermissionTo`, `syncPermissions`, `assignRole`, `removeRole`, or `syncRoles` methods.

To record when assignments are made, add `timestamps` to the pivot tables in a migration and call `withTimestamps` on the aliased relationships. Assignments then store `created_at`, and allowing or denying an assigned permission updates its `updated_at`:

```php
public function permissions(): BelongsToMany
{
    return $this->traitPermissions()->withTimestamps();
}
```

For the role-permission table, override the `permissions` method of a [custom role model](#custom-models) and the `roles` method of a custom permission model, calling `withTimestamps` on the parent's relationship. If you return roles or permissions as JSON, you may hide their pivot data by adding `pivot` to the custom model's `$hidden` property.

<a name="deleting-models"></a>
### Deleting Models

When you hard delete a model that uses `HasRoles` or `HasPermissions`, the package removes its assignments after the model's row is deleted. When the model and the permission tables use the same connection, the row and its assignments are deleted in one transaction. If a transaction is already open on that connection, the delete uses a savepoint, so a failure while removing the assignments rolls back only that delete. When they use different connections, the assignments are deleted after the model's transaction commits.

If your model defines its own `delete` method, it replaces the transaction the trait adds. Keep the model's deletion and its events inside one transaction so the row and its assignments cannot be deleted separately.

The `deleteQuietly` method skips model events, including the package's checks and cleanup. The assignment tables have no foreign key to your models, so a quiet delete may leave assignment rows behind. Use the normal `delete` method when those assignments should be removed.

<a name="uuid-and-ulid-keys"></a>
## UUID and ULID Keys

The published migration uses integer keys. If your user models use UUIDs, change the morph key column in both the `model_has_roles` and `model_has_permissions` tables before running the migration. Use `ulid` instead of `uuid` for ULIDs:

```php
// Before...
$table->unsignedBigInteger($modelMorphKey);

// After...
$table->uuid($modelMorphKey);
```

You may also rename the morph key column, for example to `model_uuid`, using the `column_names.model_morph_key` configuration value.

If your role or permission models use UUIDs, [extend the package's models](#custom-models) and add the `HasUuids` trait:

```php
use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    use HasUuids;
}
```

Then change the `id` columns of the `roles` and `permissions` tables to `$table->uuid('id')->primary()`, and the role and permission key columns of the three assignment tables to `uuid` columns. Every key column must have the same type as the key it references. For a partitioned schema, see the [partitioned schema](#partitioned-schema) example, which uses UUIDs throughout.

<a name="caching"></a>
## Caching

The package caches the role and permission catalog and each model's assignments, so permission checks usually run no queries. Within a request or job, repeated checks also reuse the values already read from the cache store.

The cache store must keep values and refreshable atomic locks on the same backend, such as the `redis`, `database`, `file`, `swoole`, or `array` stores. Stack and failover stores are not supported, since their values and locks may use different backends. The store is checked the first time the cache is filled.

To use a dedicated store, set `cache.store` in the permission configuration file to one of your cache stores. The `array` store keeps values only for the current request or job, which effectively disables caching between requests.

The cache configuration applies to the whole worker, so do not switch the permission cache store or keys for each tenant. To keep each tenant's permission data separate, use [row partitioning](#row-partitioning), which separates their database rows and cache entries.

The package's methods clear the affected cache entries for you:

```php
$role->givePermissionTo('edit articles');
$role->revokePermissionTo('edit articles');
$role->syncPermissions(['edit articles']);

$user->assignRole('writer');
$user->removeRole('writer');
$user->syncRoles(['writer']);

$user->givePermissionTo('edit articles');
$user->denyPermissionTo('delete articles');
$user->syncPermissionEffects(
    allowed: ['edit articles'],
    denied: ['delete articles'],
);
```

Changing a model's roles or direct permissions clears only that model's cached assignments. Changing a role's permissions, or creating, updating, or deleting a role or permission, clears the cached catalog. Calling `syncModels`, hard deleting a role or permission, and resetting the cache expire every model's cached assignments.

If you change the permission tables any other way, such as with raw queries, reset the cache yourself using the `permission:cache-reset` command or the `forgetCachedPermissions` method:

```shell
php artisan permission:cache-reset
```

```php
use Hypervel\Permission\PermissionRegistrar;

app(PermissionRegistrar::class)->forgetCachedPermissions();
```

If you reset the cache inside a database transaction, the reset is applied after the transaction commits and discarded if it rolls back. The method returns `true` once such a reset has been registered. When row partitioning is enabled, the reset applies only to the current partition.

<a name="testing-and-seeding"></a>
## Testing and Seeding

When row partitioning is enabled, tests and seeders must set the partition context before using roles and permissions or clearing the cache:

```php
use Hypervel\Support\Facades\Context;

Context::add('workspace_id', $workspace->getKey());
```

Keep partitioning enabled in your tests and set the context the same way production does, so a missing context or a leak between partitions fails your tests.

<a name="seeding"></a>
### Seeding

Creating roles and permissions clears the permission cache through model events. The default `DatabaseSeeder` uses the `WithoutModelEvents` trait, so the seeders it calls run without model events. Clear the cache in them after creating roles and permissions and before assigning them:

```php
use Hypervel\Database\Seeder;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        Permission::create(['name' => 'edit articles']);
        Permission::create(['name' => 'publish articles']);
        Permission::create(['name' => 'unpublish articles']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Role::create(['name' => 'writer'])
            ->givePermissionTo('edit articles');

        Role::create(['name' => 'moderator'])
            ->givePermissionTo(['publish articles', 'unpublish articles']);

        Role::create(['name' => 'admin'])
            ->givePermissionTo(Permission::all());
    }
}
```

You may assign roles to users created by a factory using an [`afterCreating` callback](/docs/{{version}}/eloquent-factories#factory-callbacks), for example in a factory state:

```php
public function editor(): static
{
    return $this->afterCreating(function (User $user) {
        $user->assignRole('editor');
    });
}
```

When seeding a large number of permissions, Eloquent's `insert` method is faster than `create`, since it skips model events and the package's checks. Provide every required column, including the guard name and any partition column, and clear the cache afterwards:

```php
Permission::insert([
    ['name' => 'edit articles', 'guard_name' => 'web'],
    ['name' => 'delete articles', 'guard_name' => 'web'],
]);

app(PermissionRegistrar::class)->forgetCachedPermissions();
```

<a name="testing"></a>
### Testing

When your tests use the `RefreshDatabase` trait, you may seed roles and permissions once after the database is migrated by adding the [`Seeder` attribute](/docs/{{version}}/database-testing#running-seeders) to your test class:

```php
use Database\Seeders\RolesAndPermissionsSeeder;
use Hypervel\Foundation\Testing\Attributes\Seeder;
use Hypervel\Foundation\Testing\RefreshDatabase;

#[Seeder(RolesAndPermissionsSeeder::class)]
class ArticleTest extends TestCase
{
    use RefreshDatabase;
}
```

If your application lets users define their own roles and permissions, you may want factories for them. [Extend the package's models](#custom-models), add the `HasFactory` trait, and define factories for your models.

<a name="best-practices"></a>
## Best Practices

Assign permissions to roles, assign roles to users, and check permissions in your application:

- users have roles;
- roles have permissions;
- your application checks permissions rather than roles wherever it can.

Detailed permission names, such as `view documents` and `edit documents`, make access easy to control. Your views, policies, controllers, and routes check these permissions using `can` and `@can`, so your application rarely needs to know role names and you may rename or restructure roles freely. Give permissions directly to a user only when that user needs an exception to their roles.

Keep direct role checks for role-management screens or rare rules that truly depend on the role itself:

```php
if ($user->hasRole('admin')) {
    // ...
}
```

When authorization depends on both the user and a specific model, combine permission checks with your application's rules in a [policy](/docs/{{version}}/authorization#creating-policies):

```php
<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;

class PostPolicy
{
    public function view(?User $user, Post $post): bool
    {
        if ($post->published) {
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($user->can('view unpublished posts')) {
            return true;
        }

        return $user->id === $post->user_id;
    }

    public function update(User $user, Post $post): bool
    {
        if ($user->can('edit all posts')) {
            return true;
        }

        return $user->can('edit own posts') && $user->id === $post->user_id;
    }
}
```

<a name="performance"></a>
## Performance

Permission checks are served from the [cache](#caching) after the first lookup, so warm checks run no queries. Loading the role and permission catalog takes three queries, and each model's role and direct permission assignments are cached separately, per team and partition when those features are enabled.

Syncing roles or permissions reads the model's current assignments once, then inserts, deletes, or updates only what changed. When assignment events are enabled and `PermissionDetachedEvent` has a listener, syncing permissions also loads the model's current permissions for the event.

When a [custom pivot model](#custom-pivot-models) is configured, methods that return `Permission` models load the relationship once per request or job and reuse it. Permission checks and `getPermissionNames` still use the cache.

If you need to display a model's roles or permissions, eager load the relationships you will render:

```php
$users = User::with(['roles.permissions', 'permissions'])->get();
```

Eager loading is not required for normal `hasPermissionTo` or `hasRole` checks, since those checks use the package cache.

<a name="exceptions"></a>
## Exceptions

The package's middleware throws a `Hypervel\Permission\Exceptions\UnauthorizedException` when authorization fails. You may customize its response using Hypervel's [exception handling](/docs/{{version}}/errors#rendering-exceptions) in your application's `bootstrap/app.php` file:

```php
use Hypervel\Foundation\Configuration\Exceptions;
use Hypervel\Permission\Exceptions\UnauthorizedException;

->withExceptions(function (Exceptions $exceptions): void {
    $exceptions->render(function (UnauthorizedException $exception) {
        return response()->json([
            'message' => 'You do not have the required authorization.',
        ], 403);
    });
})
```

The exception exposes the required roles or permissions:

```php
$exception->getRequiredRoles();

$exception->getRequiredPermissions();
```

The package's other exceptions are in the `Hypervel\Permission\Exceptions` namespace. The most common are:

- `RoleDoesNotExist` and `PermissionDoesNotExist`, when a role or permission is not found by name or ID;
- `RoleAlreadyExists` and `PermissionAlreadyExists`, when creating a role or permission that already exists for the guard;
- `GuardDoesNotMatch`, when assigning a role or permission of another guard;
- `TeamNotSelected`, when teams are enabled and roles or permissions are changed without a current team;
- `PermissionConnectionMismatch`, when a role or permission model writes through a different database connection than the configured permission model;
- `PermissionPartitionNotResolved`, when partitioning is enabled and no partition is set;
- `PermissionPartitionViolation`, when a model or pivot row belongs to a different partition than the current one, or a write would change a record's partition;
- `PermissionPartitionAlreadyConfigured`, when the partition is registered twice or after the package has started;
- `PermissionPartitionModelNotSupported`, when partitioning is enabled with role or permission models that do not extend the package's models.

<a name="credits"></a>
## Credits

Hypervel Permission began as a port of [Spatie Laravel Permission](https://github.com/spatie/laravel-permission) and has been adapted for Hypervel's framework architecture and coroutine runtime.
