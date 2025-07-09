<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Tests\Permission\Enums\Permission as PermissionEnum;
use Hypervel\Tests\Permission\Models\User;

/**
 * @internal
 * @coversNothing
 */
class HasPermissionTest extends PermissionTestCase
{
    protected User $user;

    protected Permission $viewPermission;

    protected Permission $editPermission;

    protected Permission $managePermission;

    protected Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Create test permissions
        $this->viewPermission = Permission::create([
            'name' => 'view',
            'guard_name' => 'web',
            'is_forbidden' => false,
        ]);

        $this->editPermission = Permission::create([
            'name' => 'edit',
            'guard_name' => 'web',
            'is_forbidden' => false,
        ]);

        $this->managePermission = Permission::create([
            'name' => 'manage',
            'guard_name' => 'web',
            'is_forbidden' => false,
        ]);

        // Create test role with permissions
        $this->adminRole = Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $this->adminRole->givePermissionTo('view', 'edit');
    }

    public function testUserCanBeGivenPermission()
    {
        $this->user->givePermissionTo('view');

        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasPermission('edit'));
        $this->assertCount(1, $this->user->permissions);
    }

    public function testUserCanBeGivenPermissionById()
    {
        $this->user->givePermissionTo($this->viewPermission->id);

        $this->assertTrue($this->user->hasPermission($this->viewPermission->id));
        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertCount(1, $this->user->permissions);
    }

    public function testUserCanBeGivenPermissionByEnum()
    {
        $this->user->givePermissionTo(PermissionEnum::VIEW);

        $this->assertTrue($this->user->hasPermission(PermissionEnum::VIEW));
        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertCount(1, $this->user->permissions);
    }

    public function testUserCanBeGivenMultiplePermissions()
    {
        $this->user->givePermissionTo('view', 'edit');

        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission('edit'));
        $this->assertCount(2, $this->user->permissions);
    }

    public function testUserCanBeGivenForbiddenPermission()
    {
        $this->user->giveForbiddenTo('view');
        $this->user->refresh();

        $this->assertFalse($this->user->hasPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('view'));
        $this->assertCount(1, $this->user->permissions);

        // Check that the permission exists but is forbidden
        $this->assertTrue($this->user->permissions->contains('name', 'view'));
        $this->assertTrue($this->user->permissions->where('name', 'view')->first()->pivot->is_forbidden == 1);
    }

    public function testUserCanCheckIfHasAnyPermissions()
    {
        $this->user->givePermissionTo('view');

        $this->assertTrue($this->user->hasAnyPermissions(['view', 'edit']));
        $this->assertTrue($this->user->hasAnyPermissions(['view']));
        $this->assertFalse($this->user->hasAnyPermissions(['edit']));
        $this->assertFalse($this->user->hasAnyPermissions(['manage', 'edit']));
    }

    public function testUserCanCheckIfHasAllPermissions()
    {
        $this->user->givePermissionTo('view', 'edit');

        $this->assertTrue($this->user->hasAllPermissions(['view', 'edit']));
        $this->assertTrue($this->user->hasAllPermissions(['view']));
        $this->assertFalse($this->user->hasAllPermissions(['view', 'edit', 'manage']));
    }

    public function testUserCanCheckIfHasAllDirectPermissions()
    {
        $this->user->givePermissionTo('view', 'edit');

        $this->assertTrue($this->user->hasAllDirectPermissions(['view', 'edit']));
        $this->assertTrue($this->user->hasAllDirectPermissions(['view']));
        $this->assertFalse($this->user->hasAllDirectPermissions(['view', 'edit', 'manage']));
    }

    public function testUserCanCheckIfHasAnyDirectPermissions()
    {
        $this->user->givePermissionTo('view');

        $this->assertTrue($this->user->hasAnyDirectPermissions(['view', 'edit']));
        $this->assertTrue($this->user->hasAnyDirectPermissions(['view']));
        $this->assertFalse($this->user->hasAnyDirectPermissions(['edit']));
        $this->assertFalse($this->user->hasAnyDirectPermissions(['manage', 'edit']));
    }

    public function testUserCanHavePermissionViaRoles()
    {
        $this->user->assignRole('admin');

        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasPermission('edit'));
        $this->assertFalse($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertTrue($this->user->hasPermissionViaRoles('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('edit'));
    }

    public function testUserCanHavePermissionViaBothDirectAndRole()
    {
        $this->user->givePermissionTo('view');
        $this->user->assignRole('admin');

        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertTrue($this->user->hasPermissionViaRoles('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('edit'));
    }

    public function testUserCanRevokePermission()
    {
        $this->user->givePermissionTo('view', 'edit');
        $this->assertCount(2, $this->user->permissions);

        $this->user->revokePermissionTo('view');

        $this->user->refresh();
        $this->assertFalse($this->user->hasDirectPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission('edit'));
        $this->assertCount(1, $this->user->permissions);
    }

    public function testUserCanSyncPermissions()
    {
        $this->user->givePermissionTo('view', 'edit');
        $this->assertCount(2, $this->user->permissions);

        $this->user->syncPermissions('view');

        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertCount(1, $this->user->permissions);
    }

    public function testGivePermissionDoesNotDuplicateExistingPermissions()
    {
        $this->user->givePermissionTo('view');
        $this->assertCount(1, $this->user->permissions);

        $this->user->givePermissionTo('view');

        $this->user->refresh();
        $this->assertCount(1, $this->user->permissions);
        $this->assertTrue($this->user->hasDirectPermission('view'));
    }

    public function testPermissionNormalizationWithMixedTypes()
    {
        $this->user->givePermissionTo(
            'view', // string
            $this->editPermission->id, // int
            PermissionEnum::VIEW, // enum
        );

        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission($this->editPermission->id));
        $this->assertTrue($this->user->hasDirectPermission(PermissionEnum::VIEW));

        // Should only have 2 unique permissions (view and edit)
        $this->assertCount(2, $this->user->permissions);
    }

    public function testCollectPermissionsHandlesMixedInputTypes()
    {
        $permissions = [
            'view',
            $this->editPermission->id,
            PermissionEnum::VIEW,
        ];

        $this->user->givePermissionTo($permissions);

        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertTrue($this->user->hasDirectPermission('edit'));
        $this->assertCount(2, $this->user->permissions);
    }

    public function testRoleCannotHavePermissionViaRoles()
    {
        $role = Role::create([
            'name' => 'test-role',
            'guard_name' => 'web',
        ]);

        $role->givePermissionTo('view');

        $this->assertTrue($role->hasPermission('view'));
        $this->assertTrue($role->hasDirectPermission('view'));
        $this->assertFalse($role->hasPermissionViaRoles('view'));
    }

    public function testForbiddenPermissionOverridesRolePermission()
    {
        $this->user->assignRole('admin');
        $this->assertTrue($this->user->hasPermission('view'));

        $this->user->giveForbiddenTo('view');

        $this->user->refresh();
        $this->assertFalse($this->user->hasPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('view'));
    }

    public function testUserCannotHavePermissionWhenRoleHasForbiddenPermission()
    {
        // Create a role with forbidden permission
        $restrictedRole = Role::create([
            'name' => 'restricted',
            'guard_name' => 'web',
        ]);

        // Give the role a normal permission first
        $restrictedRole->givePermissionTo('view');
        $this->assertTrue($restrictedRole->hasPermission('view'));

        // Then give the role a forbidden permission
        $restrictedRole->giveForbiddenTo('edit');
        $this->assertFalse($restrictedRole->hasPermission('edit'));

        // Assign the role to user
        $this->user->assignRole('restricted');

        // User should have 'view' permission from role
        $this->assertTrue($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('view'));

        // User should NOT have 'edit' permission because role has forbidden permission
        $this->assertFalse($this->user->hasPermission('edit'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertFalse($this->user->hasPermissionViaRoles('edit'));
    }

    public function testRoleForbiddenPermissionOverridesUserDirectPermission()
    {
        // User has direct permission
        $this->user->givePermissionTo('edit');
        $this->assertTrue($this->user->hasPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('edit'));

        // Create a role with forbidden permission for the same permission
        $restrictedRole = Role::create([
            'name' => 'restricted',
            'guard_name' => 'web',
        ]);
        $restrictedRole->giveForbiddenTo('edit');

        // Assign the role to user
        $this->user->assignRole('restricted');
        $this->user->refresh();

        // User should NOT have 'edit' permission because role has forbidden permission
        // even though user has direct permission
        $this->assertFalse($this->user->hasPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('edit')); // Direct permission still exists
        $this->assertFalse($this->user->hasPermissionViaRoles('edit')); // Role has forbidden permission
    }

    public function testGetAllPermissionsReturnsDirectAndRolePermissions()
    {
        // Give user direct permission
        $this->user->givePermissionTo('manage');

        // Assign role with additional permissions
        $this->user->assignRole('admin');

        $allPermissions = $this->user->getAllPermissions();
        // Should have direct permission + role permissions
        $permissionNames = $allPermissions->pluck('name')->toArray();
        $this->assertContains('manage', $permissionNames); // Direct permission
        $this->assertContains('view', $permissionNames);   // Role permission
        $this->assertContains('edit', $permissionNames);   // Role permission
        $this->assertCount(3, $allPermissions);
    }

    public function testGetAllPermissionsWithOnlyDirectPermissions()
    {
        $this->user->givePermissionTo('view', 'manage');

        $allPermissions = $this->user->getAllPermissions();

        $permissionNames = $allPermissions->pluck('name')->toArray();
        $this->assertContains('view', $permissionNames);
        $this->assertContains('manage', $permissionNames);
        $this->assertCount(2, $allPermissions);
    }

    public function testGetAllPermissionsWithOnlyRolePermissions()
    {
        $this->user->assignRole('admin');

        $allPermissions = $this->user->getAllPermissions();

        $permissionNames = $allPermissions->pluck('name')->toArray();
        $this->assertContains('view', $permissionNames);
        $this->assertContains('edit', $permissionNames);
        $this->assertCount(2, $allPermissions);
    }

    public function testGetPermissionsViaRolesReturnsOnlyRolePermissions()
    {
        // Give user direct permission
        $this->user->givePermissionTo('manage');

        // Assign role with permissions
        $this->user->assignRole('admin');

        $rolePermissions = $this->user->getPermissionsViaRoles();

        // Should only have role permissions, not direct permissions
        $permissionNames = $rolePermissions->pluck('name')->toArray();
        $this->assertContains('view', $permissionNames);
        $this->assertContains('edit', $permissionNames);
        $this->assertNotContains('manage', $permissionNames); // Direct permission should not be included
        $this->assertCount(2, $rolePermissions);
    }

    public function testGetPermissionsViaRolesExcludesForbiddenPermissions()
    {
        // Create role with both normal and forbidden permissions
        $mixedRole = Role::create([
            'name' => 'mixed',
            'guard_name' => 'web',
        ]);
        $mixedRole->givePermissionTo('view');
        $mixedRole->giveForbiddenTo('edit');

        $this->user->assignRole('mixed');

        $rolePermissions = $this->user->getPermissionsViaRoles();

        $permissionNames = $rolePermissions->pluck('name')->toArray();
        $this->assertContains('view', $permissionNames);
        $this->assertNotContains('edit', $permissionNames); // Forbidden permission should not be included
        $this->assertCount(1, $rolePermissions);
    }


    public function testPermissionPriorityDirectForbiddenOverridesEverything()
    {
        // User has direct normal permission and role permission
        $this->user->givePermissionTo('view');
        $this->user->assignRole('admin');
        $this->assertTrue($this->user->hasPermission('view'));

        // Revoke the direct permission first, then add forbidden permission
        $this->user->revokePermissionTo('view');
        $this->user->giveForbiddenTo('view');
        $this->user->refresh();

        $this->assertFalse($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasForbiddenPermission('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('view')); // Role permission still exists
    }

    public function testPermissionPriorityRoleForbiddenOverridesDirectPermission()
    {
        // User has direct permission
        $this->user->givePermissionTo('edit');
        $this->assertTrue($this->user->hasPermission('edit'));

        // Create role with forbidden permission
        $restrictedRole = Role::create([
            'name' => 'restricted',
            'guard_name' => 'web',
        ]);
        $restrictedRole->giveForbiddenTo('edit');

        // Assign role to user
        $this->user->assignRole('restricted');
        $this->user->refresh();

        // Should not have permission because role has forbidden it
        $this->assertFalse($this->user->hasPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('edit')); // Direct permission still exists
        $this->assertTrue($this->user->hasForbiddenPermissionViaRoles('edit')); // Role has forbidden permission
    }
}
