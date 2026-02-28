<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionManager;
use Hypervel\Tests\Permission\Models\User;

/**
 * @internal
 * @coversNothing
 */
class PermissionManagerTest extends PermissionTestCase
{
    protected PermissionManager $manager;

    protected User $user;

    protected Permission $viewPermission;

    protected Permission $editPermission;

    protected Role $adminRole;

    protected Role $editorRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = $this->app->make(PermissionManager::class);

        // Create test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Create test permissions
        $this->viewPermission = Permission::create([
            'name' => 'view',
            'guard_name' => 'web',
        ]);

        $this->editPermission = Permission::create([
            'name' => 'edit',
            'guard_name' => 'web',
        ]);

        // Create test roles
        $this->adminRole = Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $this->editorRole = Role::create([
            'name' => 'editor',
            'guard_name' => 'web',
        ]);

        // Assign permissions to roles
        $this->adminRole->givePermissionTo('view', 'edit');
        $this->editorRole->givePermissionTo('view');
    }

    public function testGetOwnerRolesCacheKey()
    {
        $cacheKey = $this->manager->getOwnerRolesCacheKey('user', 123);

        $this->assertSame('hypervel.permission.owner.roles:user:123', $cacheKey);
    }

    public function testGetOwnerPermissionsCacheKey()
    {
        $cacheKey = $this->manager->getOwnerPermissionsCacheKey('user', 123);

        $this->assertSame('hypervel.permission.owner.permissions:user:123', $cacheKey);
    }

    public function testGetAllRolesWithPermissions()
    {
        $rolesWithPermissions = $this->manager->getAllRolesWithPermissions();

        $this->assertIsArray($rolesWithPermissions);
        $this->assertArrayHasKey($this->adminRole->id, $rolesWithPermissions);
        $this->assertArrayHasKey($this->editorRole->id, $rolesWithPermissions);

        // Check admin role structure
        $adminData = $rolesWithPermissions[$this->adminRole->id];
        $this->assertArrayHasKey('role', $adminData);
        $this->assertArrayHasKey('permissions', $adminData);
        $this->assertSame($this->adminRole->name, $adminData['role']['name']);
        $this->assertCount(2, $adminData['permissions']); // view and edit permissions

        // Check editor role structure
        $editorData = $rolesWithPermissions[$this->editorRole->id];
        $this->assertArrayHasKey('role', $editorData);
        $this->assertArrayHasKey('permissions', $editorData);
        $this->assertSame($this->editorRole->name, $editorData['role']['name']);
        $this->assertCount(1, $editorData['permissions']); // only view permission
    }

    public function testGetOwnerCachedRolesReturnsNullWhenNoCache()
    {
        $cachedRoles = $this->manager->getOwnerCachedRoles('user', 999);

        $this->assertNull($cachedRoles);
    }

    public function testGetOwnerCachedPermissionsReturnsNullWhenNoCache()
    {
        $cachedPermissions = $this->manager->getOwnerCachedPermissions('user', 999);

        $this->assertNull($cachedPermissions);
    }

    public function testClearAllRolesPermissionsCache()
    {
        // First cache some data
        $rolesWithPermissions = $this->manager->getAllRolesWithPermissions();
        $this->assertNotEmpty($rolesWithPermissions);

        // Clear the cache
        $this->manager->clearAllRolesPermissionsCache();

        // Cache should be cleared, but getAllRolesWithPermissions should still work
        // (it will rebuild the cache from database)
        $rolesWithPermissionsAfterClear = $this->manager->getAllRolesWithPermissions();
        $this->assertNotEmpty($rolesWithPermissionsAfterClear);
        $this->assertEquals($rolesWithPermissions, $rolesWithPermissionsAfterClear);
    }
}
