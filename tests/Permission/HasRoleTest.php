<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Models\Role;
use Hypervel\Tests\Permission\Enums\Role as RoleEnum;
use Hypervel\Tests\Permission\Models\User;

/**
 * @internal
 * @coversNothing
 */
class HasRoleTest extends PermissionTestCase
{
    protected User $user;

    protected Role $adminRole;

    protected Role $viewerRole;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test user
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Create test roles
        $this->adminRole = Role::create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);

        $this->viewerRole = Role::create([
            'name' => 'viewer',
            'guard_name' => 'web',
        ]);
    }

    public function testUserCanBeAssignedRole()
    {
        $this->user->assignRole('admin');

        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertFalse($this->user->hasRole('viewer'));
        $this->assertCount(1, $this->user->roles);
    }

    public function testUserCanBeAssignedRoleById()
    {
        $this->user->assignRole($this->adminRole->id);

        $this->assertTrue($this->user->hasRole($this->adminRole->id));
        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertCount(1, $this->user->roles);
    }

    public function testUserCanBeAssignedRoleByEnum()
    {
        $this->user->assignRole(RoleEnum::ADMIN);

        $this->assertTrue($this->user->hasRole(RoleEnum::ADMIN));
        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertCount(1, $this->user->roles);
    }

    public function testUserCanBeAssignedMultipleRoles()
    {
        $this->user->assignRole('admin', 'viewer');

        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertTrue($this->user->hasRole('viewer'));
        $this->assertCount(2, $this->user->roles);
    }

    public function testUserCanCheckIfHasAnyRoles()
    {
        $this->user->assignRole('admin');

        $this->assertTrue($this->user->hasAnyRoles(['admin', 'viewer']));
        $this->assertTrue($this->user->hasAnyRoles(['admin']));
        $this->assertFalse($this->user->hasAnyRoles(['viewer']));
        $this->assertFalse($this->user->hasAnyRoles(['manager', 'viewer']));
    }

    public function testUserCanCheckIfHasAllRoles()
    {
        $this->user->assignRole('admin', 'viewer');

        $this->assertTrue($this->user->hasAllRoles(['admin', 'viewer']));
        $this->assertTrue($this->user->hasAllRoles(['admin']));
        $this->assertFalse($this->user->hasAllRoles(['admin', 'viewer', 'manager']));
    }

    public function testUserCanGetOnlySpecificRoles()
    {
        $this->user->assignRole('admin', 'viewer');

        $matchedRoles = $this->user->onlyRoles(['admin']);

        $this->assertCount(1, $matchedRoles);
        $this->assertEquals('admin', $matchedRoles->first()->name);

        $matchedRoles = $this->user->onlyRoles(['admin', 'viewer']);
        $this->assertCount(2, $matchedRoles);

        $matchedRoles = $this->user->onlyRoles(['manager']);
        $this->assertCount(0, $matchedRoles);
    }

    public function testUserCanRemoveRole()
    {
        $this->user->assignRole('admin', 'viewer');
        $this->assertCount(2, $this->user->roles);

        $this->user->removeRole('admin');

        $this->user->refresh();
        $this->assertFalse($this->user->hasRole('admin'));
        $this->assertTrue($this->user->hasRole('viewer'));
        $this->assertCount(1, $this->user->roles);
    }

    public function testUserCanSyncRoles()
    {
        $this->user->assignRole('admin', 'viewer');
        $this->assertCount(2, $this->user->roles);

        $this->user->syncRoles('admin');

        $this->user->refresh();
        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertFalse($this->user->hasRole('viewer'));
        $this->assertCount(1, $this->user->roles);
    }

    public function testAssignRoleDoesNotDuplicateExistingRoles()
    {
        $this->user->assignRole('admin');
        $this->assertCount(1, $this->user->roles);

        $this->user->assignRole('admin');

        $this->user->refresh();
        $this->assertCount(1, $this->user->roles);
        $this->assertTrue($this->user->hasRole('admin'));
    }

    public function testRoleNormalizationWithMixedTypes()
    {
        $this->user->assignRole(
            'admin', // string
            $this->viewerRole->id, // int
            RoleEnum::ADMIN, // enum
        );

        $this->user->refresh();
        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertTrue($this->user->hasRole($this->viewerRole->id));
        $this->assertTrue($this->user->hasRole(RoleEnum::ADMIN));

        // Should only have 2 unique roles (admin and viewer, manager is duplicate)
        $this->assertCount(2, $this->user->roles);
    }

    public function testCollectRolesHandlesMixedInputTypes()
    {
        $roles = [
            'admin',
            $this->viewerRole->id,
            RoleEnum::ADMIN,
        ];

        $this->user->assignRole($roles);

        $this->user->refresh();
        $this->assertTrue($this->user->hasRole('admin'));
        $this->assertTrue($this->user->hasRole('viewer'));
        $this->assertCount(2, $this->user->roles);
    }
}
