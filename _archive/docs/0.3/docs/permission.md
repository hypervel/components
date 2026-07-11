# Hypervel Permission

[[toc]]

## Introduction

Modern web applications often need to control what users can and cannot do. Whether you're building a content management system where editors can publish articles but only admins can manage users, or an e-commerce platform where different staff members have access to different parts of the system, you'll need a robust way to manage permissions and user roles.

The Hypervel Permission package provides a flexible and powerful solution for implementing role-based access control (RBAC) in your applications. This package is ported from [Spatie's Permission package](https://spatie.be/docs/laravel-permission) but optimized for the Hypervel framework with advanced caching capabilities and support for modern PHP features. Instead of hardcoding permission checks throughout your codebase, you can define roles like "editor" or "admin", assign specific permissions to those roles, and then simply check if a user has the required role or permission. The package handles all the complex database queries and caching behind the scenes.

What makes this package particularly powerful is its flexibility - you can assign roles to users for broad access control, assign specific permissions for fine-grained control, or even use "forbidden permissions" to explicitly deny access to certain actions. All permission checks are optimized with intelligent caching, so you don't have to worry about performance even with complex permission hierarchies.

## Installation

You can install the permission package via Composer:

```bash
composer require hypervel/permission
```

The package will automatically register its service provider.

### Publishing Assets

Publish the migration and configuration files:

```bash
php artisan vendor:publish "Hypervel\Permission\PermissionServiceProvider"
```

Or publish them separately:

```bash
# Publish config only
php artisan vendor:publish "Hypervel\Permission\PermissionServiceProvider" --tag="permission-config"

# Publish migrations only
php artisan vendor:publish "Hypervel\Permission\PermissionServiceProvider" --tag="permission-migrations"
```

### Running Migrations

After publishing, run the migrations to create the necessary tables:

```bash
php artisan migrate
```

This will create the following tables:
- `roles` - stores role information
- `permissions` - stores permission information
- `role_has_permissions` - pivot table for role-permission relationships
- `owner_has_permissions` - polymorphic table for model-permission relationships
- `owner_has_roles` - polymorphic table for model-role relationships

## Configuration

The package configuration file `config/permission.php` allows you to customize various aspects:

### Models

You can specify custom models for roles and permissions:

```php
'models' => [
    'role' => \App\Models\Role::class,
    'permission' => \App\Models\Permission::class,
],
```

### Table Names

Customize table names to match your database schema:

```php
'table_names' => [
    'roles' => 'roles',
    'permissions' => 'permissions',
    'role_has_permissions' => 'role_has_permissions',
    'owner_has_permissions' => 'owner_has_permissions',
    'owner_has_roles' => 'owner_has_roles',
],
```

### Column Names

Customize column names used in relationships:

```php
'column_names' => [
    'role_pivot_key' => 'role_id',
    'permission_pivot_key' => 'permission_id',
    'owner_morph_key' => 'owner_id',
    'owner_name' => 'owner',
],
```

### Cache Configuration

Configure caching behavior for optimal performance:

```php
'cache' => [
    'expiration_seconds' => 86400, // 24 hours
    'keys' => [
        'roles' => 'hypervel.permission.cache.roles',
        'owner_roles' => 'hypervel.permission.cache.owner.roles',
        'owner_permissions' => 'hypervel.permission.cache.owner.permissions',
    ],
    'store' => env('PERMISSION_CACHE_STORE', 'default'),
],
```

## Basic Usage

### Adding Traits to Models

To use permissions and roles with your models, add the appropriate traits:

#### For Users (or any model that should have roles and permissions):

```php
namespace App\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Traits\HasRole;

class User extends Model
{
    use HasRole; // This also includes HasPermission trait

    // Your model code...
}
```

#### For Custom Permission Model:

```php
namespace App\Models;

use Hypervel\Permission\Models\Permission as BasePermission;

class Permission extends BasePermission
{
    // Add custom methods or properties
}
```

#### For Custom Role Model:

```php
namespace App\Models;

use Hypervel\Permission\Models\Role as BaseRole;

class Role extends BaseRole
{
    // Add custom methods or properties
}
```

### Creating Permissions and Roles

#### Creating Permissions

```php
use Hypervel\Permission\Models\Permission;

// Create permissions
$permission = Permission::create(['name' => 'edit articles', 'guard_name' => 'web']);
$permission = Permission::create(['name' => 'delete articles', 'guard_name' => 'web']);
$permission = Permission::create(['name' => 'publish articles', 'guard_name' => 'web']);
```

#### Creating Roles

```php
use Hypervel\Permission\Models\Role;

// Create roles
$role = Role::create(['name' => 'writer', 'guard_name' => 'web']);
$role = Role::create(['name' => 'editor', 'guard_name' => 'web']);
$role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
```

#### Assigning Permissions to Roles

```php
// Assign permissions to roles
$role = Role::findByName('writer');
$role->givePermissionTo('edit articles');

// Assign multiple permissions
$role->givePermissionTo(['edit articles', 'delete articles']);

// Sync permissions (replaces all existing permissions)
$role->syncPermissions(['edit articles', 'publish articles']);
```

## Working with Roles

### Assigning Roles to Models

```php
// Assign a role to a user
$user->assignRole('writer');

// Assign multiple roles
$user->assignRole(['writer', 'editor']);

// You can also use IDs
$user->assignRole(1);

// Sync roles (replaces all existing roles)
$user->syncRoles(['writer', 'editor']);
```

### Checking Roles

```php
// Check if user has a specific role
if ($user->hasRole('writer')) {
    // User has writer role
}

// Check if user has any of the specified roles
if ($user->hasAnyRoles(['writer', 'editor'])) {
    // User has at least one of these roles
}

// Check if user has all specified roles
if ($user->hasAllRoles(['writer', 'editor'])) {
    // User has both roles
}

// Get specific roles that match
$matchingRoles = $user->onlyRoles(['writer', 'admin']);
```

### Removing Roles

```php
// Remove a role
$user->removeRole('writer');

// Remove multiple roles
$user->removeRole(['writer', 'editor']);
```

## Working with Permissions

### Assigning Permissions to Models

```php
// Give permission directly to user
$user->givePermissionTo('edit articles');

// Give multiple permissions
$user->givePermissionTo(['edit articles', 'delete articles']);

// Sync permissions (replaces all existing permissions)
$user->syncPermissions(['edit articles', 'publish articles']);
```

### Checking Permissions

```php
// Check if user has a specific permission
if ($user->hasPermission('edit articles')) {
    // User has permission (either direct or via role)
}

// Check direct permissions only
if ($user->hasDirectPermission('edit articles')) {
    // User has direct permission (not via role)
}

// Check if user has permission via roles
if ($user->hasPermissionViaRoles('edit articles')) {
    // User has permission through assigned roles
}

// Check if user has any of the specified permissions
if ($user->hasAnyPermissions(['edit articles', 'delete articles'])) {
    // User has at least one permission
}

// Check if user has all specified permissions
if ($user->hasAllPermissions(['edit articles', 'delete articles'])) {
    // User has all permissions
}
```

### Forbidden Permissions

The package supports forbidden permissions that explicitly deny access:

```php
// Give forbidden permission (denies access)
$user->giveForbiddenTo('delete articles');

// Check if user has forbidden permission
if ($user->hasForbiddenPermission('delete articles')) {
    // Access is explicitly denied
}

// Forbidden permissions override allowed permissions
$user->givePermissionTo('delete articles');
$user->giveForbiddenTo('delete articles');
// hasPermission('delete articles') will return false
```

### Revoking Permissions

```php
// Revoke permission
$user->revokePermissionTo('edit articles');

// Revoke multiple permissions
$user->revokePermissionTo(['edit articles', 'delete articles']);
```

### Getting All Permissions

```php
// Get all permissions (direct + via roles, excluding forbidden)
$permissions = $user->getAllPermissions();

// Get permissions via roles only
$rolePermissions = $user->getPermissionsViaRoles();
```

## Using Enums

The package supports PHP 8.1+ enums for type-safe permission and role management:

### Backed Enums

```php
enum Permission: string
{
    case EDIT_ARTICLES = 'edit articles';
    case DELETE_ARTICLES = 'delete articles';
    case PUBLISH_ARTICLES = 'publish articles';
}

enum Role: string
{
    case WRITER = 'writer';
    case EDITOR = 'editor';
    case ADMIN = 'admin';
}

// Usage with enums
$user->assignRole(Role::WRITER);
$user->givePermissionTo(Permission::EDIT_ARTICLES);

if ($user->hasPermission(Permission::EDIT_ARTICLES)) {
    // User can edit articles
}
```

### Unit Enums

```php
enum SimplePermission
{
    case EDIT_ARTICLES;
    case DELETE_ARTICLES;
    case PUBLISH_ARTICLES;
}

// The enum name will be used as the permission name
$user->givePermissionTo(SimplePermission::EDIT_ARTICLES);
```

## Middleware

The package provides middleware for protecting routes based on roles and permissions.

### Permission Middleware

```php
use Hypervel\Permission\Middlewares\PermissionMiddleware;

// In your route definitions
$router->get('/admin', [AdminController::class, 'index'], [
    'middleware' => [PermissionMiddleware::using('view admin')],
]);

// Multiple permissions (user needs ANY of them)
$router->get('/posts/edit', [PostController::class, 'edit'], [
    'middleware' => [PermissionMiddleware::using('edit articles', 'edit all')],
]);
```

### Role Middleware

```php
use Hypervel\Permission\Middlewares\RoleMiddleware;

// In your route definitions
$router->get('/admin', [AdminController::class, 'index'], [
    'middleware' => [RoleMiddleware::using('admin')],
]);

// Multiple roles (user needs ANY of them)
$router->get('/editor', [EditorController::class, 'index'], [
    'middleware' => [RoleMiddleware::using('editor', 'admin')],
]);
```

### Using with Enums

```php
$router->get('/admin', [AdminController::class, 'index'], [
    'middleware' => [PermissionMiddleware::using(Permission::VIEW_ADMIN)],
]);

$router->get('/editor', [EditorController::class, 'index'], [
    'middleware' => [RoleMiddleware::using(Role::EDITOR, Role::ADMIN)],
]);
```

## Console Commands

### Viewing Permissions and Roles

Use the built-in command to view the permission matrix:

```bash
# Show all permissions and roles
php artisan permission:show

# Show for specific guard
php artisan permission:show web

# Use different table style
php artisan permission:show web compact
```

## Advanced Usage

### Custom Guards

The package supports multiple guards:

```php
// Create permissions for different guards
Permission::create(['name' => 'view admin', 'guard_name' => 'web']);
Permission::create(['name' => 'api access', 'guard_name' => 'api']);

// Roles with different guards
Role::create(['name' => 'admin', 'guard_name' => 'web']);
Role::create(['name' => 'api-user', 'guard_name' => 'api']);
```

### Polymorphic Relationships

The package uses polymorphic relationships, so any model can have roles and permissions:

```php
class Team extends Model
{
    use HasRole;
}

$team = Team::find(1);
$team->assignRole('project-manager');
$team->givePermissionTo('manage projects');
```

### Working with the Permission Manager

The `PermissionManager` class provides advanced functionality:

```php
use Hypervel\Permission\PermissionManager;

$manager = app(PermissionManager::class);

// Get role and permission classes
$roleClass = $manager->getRoleClass();
$permissionClass = $manager->getPermissionClass();

// Cache management
$manager->clearAllRolesPermissionsCache();
$manager->clearOwnerCache(User::class, $userId);

// Get cached data
$allRolesWithPermissions = $manager->getAllRolesWithPermissions();
$cachedRoles = $manager->getOwnerCachedRoles(User::class, $userId);
$cachedPermissions = $manager->getOwnerCachedPermissions(User::class, $userId);
```

### Custom Models

If you want to extend the base models:

```php
// Custom Permission Model
class CustomPermission extends \Hypervel\Permission\Models\Permission
{
    protected array $fillable = ['name', 'guard_name', 'description'];

    public function getDescriptionAttribute($value)
    {
        return ucfirst($value);
    }
}

// Custom Role Model
class CustomRole extends \Hypervel\Permission\Models\Role
{
    protected array $fillable = ['name', 'guard_name', 'description'];

    public function users()
    {
        return $this->morphedByMany(User::class, 'owner', 'owner_has_roles');
    }
}

// Update config
'models' => [
    'permission' => CustomPermission::class,
    'role' => CustomRole::class,
],
```

## Caching

The package includes sophisticated caching to ensure high performance:

### Cache Strategy

- **All Roles with Permissions**: Cached globally to avoid N+1 queries when checking role-based permissions
- **Owner Roles**: Individual user/model roles are cached separately
- **Owner Permissions**: Individual user/model permissions are cached separately

### Cache Invalidation

Cache is automatically cleared when:
- Roles or permissions are assigned/revoked
- Role permissions are modified
- Models with roles/permissions are updated

### Manual Cache Management

```php
use Hypervel\Permission\PermissionManager;

$manager = app(PermissionManager::class);

// Clear all caches
$manager->clearAllRolesPermissionsCache();

// Clear specific owner cache
$manager->clearOwnerCache(User::class, $user->id);

// Get cache store
$cache = $manager->getCache();
```

## Performance Considerations

### Optimizing Queries

The package is designed to minimize database queries through caching:

```php
// This loads and caches all roles with their permissions in one query
$allRolesWithPermissions = $manager->getAllRolesWithPermissions();

// Subsequent permission checks use cached data
$user->hasPermissionViaRoles('edit articles'); // No additional queries
```

### Eager Loading

When working with multiple models, consider eager loading:

```php
// Load users with their roles and permissions
$users = User::with(['roles.permissions', 'permissions'])->get();

// Now permission checks won't trigger additional queries
foreach ($users as $user) {
    if ($user->hasPermission('edit articles')) {
        // Process user
    }
}
```

### Cache Warming

For high-traffic applications, warm the cache on deployment:

```php
use Hypervel\Permission\PermissionManager;

// In a deployment script or command
$manager = app(PermissionManager::class);
$manager->getAllRolesWithPermissions(); // Warms the cache
```

## Common Patterns

### Hierarchical Permissions

```php
// Create a hierarchy using role inheritance
$admin = Role::create(['name' => 'admin', 'guard_name' => 'web']);
$editor = Role::create(['name' => 'editor', 'guard_name' => 'web']);
$writer = Role::create(['name' => 'writer', 'guard_name' => 'web']);

// Assign cumulative permissions
$writer->givePermissionTo(['create articles', 'edit own articles']);
$editor->givePermissionTo(['create articles', 'edit own articles', 'edit all articles', 'publish articles']);

// Users get permissions through role assignment
$user->assignRole('editor'); // Gets all editor permissions
```

### Resource-Based Permissions

```php
// Create permissions for specific resources
Permission::create(['name' => 'view articles', 'guard_name' => 'web']);
Permission::create(['name' => 'create articles', 'guard_name' => 'web']);
Permission::create(['name' => 'edit articles', 'guard_name' => 'web']);
Permission::create(['name' => 'delete articles', 'guard_name' => 'web']);

Permission::create(['name' => 'view users', 'guard_name' => 'web']);
Permission::create(['name' => 'create users', 'guard_name' => 'web']);
Permission::create(['name' => 'edit users', 'guard_name' => 'web']);
Permission::create(['name' => 'delete users', 'guard_name' => 'web']);
```

### Dynamic Permission Checking

```php
class ArticleController extends Controller
{
    public function show(Article $article)
    {
        // Check if user can view this specific article
        if (!$this->canView($article)) {
            throw new UnauthorizedException('Cannot view this article');
        }

        return view('articles.show', compact('article'));
    }

    private function canView(Article $article): bool
    {
        $user = auth()->user();

        // Admin can view all
        if ($user->hasRole('admin')) {
            return true;
        }

        // Users can view published articles
        if ($article->is_published && $user->hasPermission('view articles')) {
            return true;
        }

        // Authors can view their own articles
        if ($article->author_id === $user->id && $user->hasPermission('view own articles')) {
            return true;
        }

        return false;
    }
}
```