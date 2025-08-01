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

    protected Permission $deletePermission;

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

        $this->deletePermission = Permission::create([
            'name' => 'delete',
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

        $this->user->syncPermissions(['view']);

        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertCount(1, $this->user->permissions);
    }

    public function testUserCanSyncPermissionsWithEmpty()
    {
        $this->user->givePermissionTo('view', 'edit');
        $this->assertCount(2, $this->user->permissions);

        $this->user->syncPermissions();

        $this->user->refresh();
        $this->assertFalse($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertCount(0, $this->user->permissions);
    }

    public function testUserCanSyncPermissionsWithForbidden()
    {
        $this->user->givePermissionTo('view');
        $this->user->giveForbiddenTo('edit');
        $this->assertCount(2, $this->user->permissions);

        $this->user->syncPermissions(['manage'], ['delete']);

        $this->user->refresh();
        $this->assertFalse($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('manage'));
        $this->assertFalse($this->user->hasPermission('delete'));
        $this->assertTrue($this->user->hasForbiddenPermission('delete'));
        $this->assertCount(2, $this->user->permissions);
    }

    public function testUserSyncPermissionsWithMixedTypes()
    {
        $this->user->givePermissionTo('view', 'edit');

        $this->user->syncPermissions([
            'manage',
            $this->deletePermission->id,
            PermissionEnum::VIEW,
        ]);

        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('manage'));
        $this->assertTrue($this->user->hasDirectPermission($this->deletePermission->id));
        $this->assertTrue($this->user->hasDirectPermission(PermissionEnum::VIEW));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertCount(3, $this->user->permissions);
    }

    public function testUserSyncPermissionsForbiddenOverridesAllowed()
    {
        // If a permission is in both allowed and forbidden arrays, forbidden takes precedence
        $this->user->syncPermissions(['view', 'edit'], ['edit', 'manage']);

        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasPermission('edit'));
        $this->assertTrue($this->user->hasForbiddenPermission('edit'));
        $this->assertFalse($this->user->hasPermission('manage'));
        $this->assertTrue($this->user->hasForbiddenPermission('manage'));
        $this->assertCount(3, $this->user->permissions);

        // Check pivot values
        $viewPermission = $this->user->permissions->where('name', 'view')->first();
        $editPermission = $this->user->permissions->where('name', 'edit')->first();
        $managePermission = $this->user->permissions->where('name', 'manage')->first();

        $this->assertEquals(0, $viewPermission->pivot->is_forbidden);
        $this->assertEquals(1, $editPermission->pivot->is_forbidden);
        $this->assertEquals(1, $managePermission->pivot->is_forbidden);
    }

    public function testUserSyncPermissionsReturnsChanges()
    {
        $this->user->givePermissionTo('view', 'edit');

        $result = $this->user->syncPermissions(['view', 'manage'], ['delete']);

        // Check sync results
        $this->assertArrayHasKey('attached', $result);
        $this->assertArrayHasKey('detached', $result);
        $this->assertArrayHasKey('updated', $result);

        // Check what was attached (both manage with is_forbidden=false and delete with is_forbidden=true)
        $attachedIds = array_keys($result['attached']);
        $this->assertGreaterThanOrEqual(2, count($attachedIds));

        // Verify actual permissions after sync
        $this->user->refresh();
        $this->assertTrue($this->user->hasDirectPermission('view'));
        $this->assertFalse($this->user->hasDirectPermission('edit'));
        $this->assertTrue($this->user->hasDirectPermission('manage'));
        $this->assertTrue($this->user->hasForbiddenPermission('delete'));

        // edit should be detached
        $this->assertCount(1, $result['detached']);
        $this->assertContains($this->editPermission->id, $result['detached']);
    }

    public function testUserSyncPermissionsHandlesPivotChanges()
    {
        // User has view as allowed and edit as forbidden
        $this->user->givePermissionTo('view');
        $this->user->giveForbiddenTo('edit');

        // Sync with view as forbidden and edit as allowed
        $result = $this->user->syncPermissions(['edit'], ['view']);

        $this->user->refresh();
        $this->assertFalse($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasForbiddenPermission('view'));
        $this->assertTrue($this->user->hasPermission('edit'));
        $this->assertFalse($this->user->hasForbiddenPermission('edit'));

        // Both permissions should show as updated in sync result
        $this->assertCount(2, $result['updated']);
    }

    public function testUserSyncPermissionsClearsCache()
    {
        $this->user->givePermissionTo('view');

        // Trigger cache by checking permission
        $this->assertTrue($this->user->hasPermission('view'));

        // Sync permissions
        $this->user->syncPermissions(['edit']);

        // Refresh the user to get the latest permissions from database
        $this->user->refresh();

        // Cache should be cleared, so new permissions are reflected
        $this->assertFalse($this->user->hasPermission('view'));
        $this->assertTrue($this->user->hasPermission('edit'));
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

    public function testRoleCanSyncPermissions()
    {
        $this->adminRole->givePermissionTo('view', 'edit');
        $this->assertCount(2, $this->adminRole->permissions);

        $this->adminRole->syncPermissions(['view']);

        $this->adminRole->refresh();
        $this->assertTrue($this->adminRole->hasDirectPermission('view'));
        $this->assertFalse($this->adminRole->hasDirectPermission('edit'));
        $this->assertCount(1, $this->adminRole->permissions);
    }

    public function testRoleCanSyncPermissionsWithEmpty()
    {
        $this->adminRole->givePermissionTo('view', 'edit');
        $this->assertCount(2, $this->adminRole->permissions);

        $this->adminRole->syncPermissions();

        $this->adminRole->refresh();
        $this->assertFalse($this->adminRole->hasDirectPermission('view'));
        $this->assertFalse($this->adminRole->hasDirectPermission('edit'));
        $this->assertCount(0, $this->adminRole->permissions);
    }

    public function testRoleCanSyncPermissionsWithForbidden()
    {
        $this->adminRole->givePermissionTo('view');
        $this->adminRole->givePermissionTo('edit');
        $this->assertCount(2, $this->adminRole->permissions);

        $this->adminRole->syncPermissions(['manage'], ['delete']);

        $this->adminRole->refresh();
        $this->assertFalse($this->adminRole->hasDirectPermission('view'));
        $this->assertFalse($this->adminRole->hasDirectPermission('edit'));
        $this->assertTrue($this->adminRole->hasDirectPermission('manage'));
        $this->assertFalse($this->adminRole->hasPermission('delete'));
        $this->assertTrue($this->adminRole->hasForbiddenPermission('delete'));
        $this->assertCount(2, $this->adminRole->permissions);
    }

    public function testRoleSyncPermissionsWithMixedTypes()
    {
        $this->adminRole->givePermissionTo('view', 'edit');

        $this->adminRole->syncPermissions([
            'manage',
            $this->deletePermission->id,
            PermissionEnum::VIEW,
        ]);

        $this->adminRole->refresh();
        $this->assertTrue($this->adminRole->hasDirectPermission('manage'));
        $this->assertTrue($this->adminRole->hasDirectPermission($this->deletePermission->id));
        $this->assertTrue($this->adminRole->hasDirectPermission(PermissionEnum::VIEW));
        $this->assertFalse($this->adminRole->hasDirectPermission('edit'));
        $this->assertCount(3, $this->adminRole->permissions);
    }

    public function testRoleSyncPermissionsForbiddenOverridesAllowed()
    {
        // If a permission is in both allowed and forbidden arrays, forbidden takes precedence
        $this->adminRole->syncPermissions(['view', 'edit'], ['edit', 'manage']);

        $this->adminRole->refresh();
        $this->assertTrue($this->adminRole->hasDirectPermission('view'));
        $this->assertFalse($this->adminRole->hasPermission('edit'));
        $this->assertTrue($this->adminRole->hasForbiddenPermission('edit'));
        $this->assertFalse($this->adminRole->hasPermission('manage'));
        $this->assertTrue($this->adminRole->hasForbiddenPermission('manage'));
        $this->assertCount(3, $this->adminRole->permissions);
    }

    public function testRoleSyncPermissionsReturnsChanges()
    {
        $this->adminRole->givePermissionTo('view', 'edit');

        $result = $this->adminRole->syncPermissions(['view', 'manage'], ['delete']);

        // Check sync results
        $this->assertArrayHasKey('attached', $result);
        $this->assertArrayHasKey('detached', $result);
        $this->assertArrayHasKey('updated', $result);

        // manage and delete should be attached
        $this->assertCount(2, $result['attached']);
        $this->assertContains($this->managePermission->id, $result['attached']);
        $this->assertContains($this->deletePermission->id, $result['attached']);

        // edit should be detached
        $this->assertCount(1, $result['detached']);
        $this->assertContains($this->editPermission->id, $result['detached']);
    }

    public function testRoleSyncPermissionsClearsAllRolesCache()
    {
        $this->adminRole->givePermissionTo('view');

        // Create another role
        $editorRole = Role::create([
            'name' => 'editor',
            'guard_name' => 'web',
        ]);
        $editorRole->givePermissionTo('edit');

        // Assign roles to user to trigger caching
        $this->user->assignRole('admin', 'editor');
        $this->assertTrue($this->user->hasPermissionViaRoles('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('edit'));

        // Sync permissions on admin role
        $this->adminRole->syncPermissions(['manage']);

        // Check that permissions are updated via roles
        $this->user->refresh();
        $this->assertFalse($this->user->hasPermissionViaRoles('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('manage'));
        $this->assertTrue($this->user->hasPermissionViaRoles('edit')); // Editor role still has this
    }

    public function testRoleSyncPermissionsAffectsUsersWithRole()
    {
        // Assign role to multiple users
        $this->adminRole->givePermissionTo('view', 'edit');
        $this->user->assignRole('admin');

        $anotherUser = User::create([
            'name' => 'Another User',
            'email' => 'another@example.com',
        ]);
        $anotherUser->assignRole('admin');

        // Both users should have permissions via role
        $this->assertTrue($this->user->hasPermissionViaRoles('view'));
        $this->assertTrue($this->user->hasPermissionViaRoles('edit'));
        $this->assertTrue($anotherUser->hasPermissionViaRoles('view'));
        $this->assertTrue($anotherUser->hasPermissionViaRoles('edit'));

        // Sync role permissions
        $this->adminRole->syncPermissions(['manage'], ['delete']);

        // Both users should reflect the changes
        $this->user->refresh();
        $anotherUser->refresh();

        $this->assertFalse($this->user->hasPermissionViaRoles('view'));
        $this->assertFalse($this->user->hasPermissionViaRoles('edit'));
        $this->assertTrue($this->user->hasPermissionViaRoles('manage'));
        $this->assertFalse($this->user->hasPermission('delete')); // Forbidden via role

        $this->assertFalse($anotherUser->hasPermissionViaRoles('view'));
        $this->assertFalse($anotherUser->hasPermissionViaRoles('edit'));
        $this->assertTrue($anotherUser->hasPermissionViaRoles('manage'));
        $this->assertFalse($anotherUser->hasPermission('delete')); // Forbidden via role
    }

    public function testRoleSyncPermissionsHandlesPivotChanges()
    {
        // Role has view as allowed and edit not attached
        $this->adminRole->givePermissionTo('view');

        // Sync with view as forbidden and edit as allowed
        $result = $this->adminRole->syncPermissions(['edit'], ['view']);

        $this->adminRole->refresh();
        $this->assertFalse($this->adminRole->hasPermission('view'));
        $this->assertTrue($this->adminRole->hasForbiddenPermission('view'));
        $this->assertTrue($this->adminRole->hasPermission('edit'));
        $this->assertFalse($this->adminRole->hasForbiddenPermission('edit'));

        // Check results - since view is in both arrays (allowed then forbidden),
        // it might be attached with forbidden flag, not updated
        $totalChanges = count($result['attached']) + count($result['updated']);
        $this->assertGreaterThanOrEqual(2, $totalChanges);

        // Verify the permissions are correctly set
        $permissions = $this->adminRole->permissions()->get();
        $this->assertCount(2, $permissions);

        $viewPerm = $permissions->where('id', $this->viewPermission->id)->first();
        $editPerm = $permissions->where('id', $this->editPermission->id)->first();

        $this->assertNotNull($viewPerm);
        $this->assertEquals(1, $viewPerm->pivot->is_forbidden);
        $this->assertNotNull($editPerm);
        $this->assertEquals(0, $editPerm->pivot->is_forbidden);
    }

    public function testRoleGetAllPermission()
    {
        // Create another role
        $editorRole = Role::create([
            'name' => 'editor',
            'guard_name' => 'web',
        ]);

        $editorRole->givePermissionTo('edit');
        $permissionNames = $editorRole->getAllPermissions()->pluck('name')->toArray();
        $this->assertContains('edit', $permissionNames);
        $this->assertCount(1, $permissionNames);

        $editorRole->givePermissionTo('view');
        $permissionNames = $editorRole->getAllPermissions()->pluck('name')->toArray();
        $this->assertCount(2, $permissionNames);
    }

    public function testRoleCanBeGivenForbiddenPermission()
    {
        $this->adminRole->giveForbiddenTo('manage');

        $this->assertFalse($this->adminRole->hasPermission('manage'));
        $this->assertFalse($this->adminRole->hasDirectPermission('manage'));
        $this->assertTrue($this->adminRole->hasForbiddenPermission('manage'));
        $this->assertCount(3, $this->adminRole->permissions); // view, edit from setUp + forbidden manage

        // Check that the permission exists but is forbidden
        $this->assertTrue($this->adminRole->permissions->contains('name', 'manage'));
        $this->assertTrue($this->adminRole->permissions->where('name', 'manage')->first()->pivot->is_forbidden == 1);
    }

    public function testRoleCanRevokePermission()
    {
        $this->adminRole->givePermissionTo('manage');
        $this->assertCount(3, $this->adminRole->permissions); // view, edit from setUp + manage

        $this->adminRole->revokePermissionTo('manage');

        $this->adminRole->refresh();
        $this->assertFalse($this->adminRole->hasDirectPermission('manage'));
        $this->assertTrue($this->adminRole->hasDirectPermission('view'));
        $this->assertTrue($this->adminRole->hasDirectPermission('edit'));
        $this->assertCount(2, $this->adminRole->permissions);
    }

    public function testRoleGetPermissionsViaRolesReturnsEmpty()
    {
        // Roles should not have permissions via other roles
        $rolePermissions = $this->adminRole->getPermissionsViaRoles();
        $this->assertTrue($rolePermissions->isEmpty());
    }

    public function testRoleGetAllPermissionsExcludesForbiddenPermissions()
    {
        $this->adminRole->givePermissionTo('manage');
        $this->adminRole->giveForbiddenTo('delete');

        $allPermissions = $this->adminRole->getAllPermissions();

        $permissionNames = $allPermissions->pluck('name')->toArray();
        $this->assertContains('view', $permissionNames);
        $this->assertContains('edit', $permissionNames);
        $this->assertContains('manage', $permissionNames);
        $this->assertNotContains('delete', $permissionNames); // Forbidden permission should not be included
        $this->assertCount(3, $allPermissions);
    }
}
