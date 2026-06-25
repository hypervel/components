<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Tests\Permission\Fixtures\Models\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\Role;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class ForbiddenPermissionTest extends TestCase
{
    public function testDirectForbiddenPermissionDeniesDirectAndNormalPermissionChecks(): void
    {
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue((bool) $this->testUser->permissions->firstWhere('name', 'edit-articles')->pivot->getAttribute('is_forbidden'));
    }

    public function testDirectForbiddenPermissionOverridesRolePermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->testUser->assignRole('testRole');
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->getAllPermissions()->contains('name', 'edit-articles'));
    }

    public function testRoleForbiddenPermissionOverridesDirectPermission(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'restricted']);
        $role->giveForbiddenTo('edit-articles');

        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->assignRole($role);

        $this->assertTrue($this->testUser->hasForbiddenPermissionViaRoles('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->getAllPermissions()->contains('name', 'edit-articles'));
    }

    public function testForbiddenPermissionWinsWhenAllowedAndForbiddenAreSyncedTogether(): void
    {
        $changes = $this->testUser->syncPermissionsWithForbidden(
            allowed: ['edit-articles', 'edit-news'],
            forbidden: ['edit-news'],
        );

        $this->assertArrayHasKey('attached', $changes);
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-news'));
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-news'));
    }

    public function testForbiddenSyncReplacesExistingDirectPermissions(): void
    {
        $this->app->make(PermissionContract::class)::create(['name' => 'delete-articles']);

        $this->testUser->givePermissionTo('edit-articles', 'edit-news');
        $changes = $this->testUser->syncPermissionsWithForbidden(
            allowed: ['edit-news'],
            forbidden: ['delete-articles'],
        );

        $this->testUser->refresh();

        $this->assertEqualsCanonicalizing([
            $this->testUserPermission->getKey(),
        ], $changes['detached']);
        $this->assertEqualsCanonicalizing([
            $this->app->make(PermissionContract::class)::findByName('delete-articles')->getKey(),
        ], $changes['attached']);
        $this->assertEqualsCanonicalizing([
            $this->app->make(PermissionContract::class)::findByName('edit-news')->getKey(),
        ], $changes['updated']);
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-news'));
        $this->assertTrue($this->testUser->hasForbiddenPermission('delete-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('delete-articles'));
    }

    public function testForbiddenSyncReportsPivotChangesAsUpdates(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->giveForbiddenTo('edit-news');

        $changes = $this->testUser->syncPermissionsWithForbidden(
            allowed: ['edit-news'],
            forbidden: ['edit-articles'],
        );

        $this->testUser->refresh();

        $this->assertSame([], $changes['attached']);
        $this->assertSame([], $changes['detached']);
        $this->assertEqualsCanonicalizing([
            $this->testUserPermission->getKey(),
            $this->app->make(PermissionContract::class)::findByName('edit-news')->getKey(),
        ], $changes['updated']);
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-news'));
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-news'));
    }

    public function testRoleForbiddenSyncUsesCustomPermissionPrimaryKeys(): void
    {
        $this->setUpCustomModels();

        $allowedPermission = Permission::findOrCreate('custom-allow');
        $forbiddenPermission = Permission::findOrCreate('custom-deny');
        $role = Role::findOrCreate('custom-role');

        $changes = $role->syncPermissionsWithForbidden(
            allowed: [$allowedPermission],
            forbidden: [$forbiddenPermission],
        );

        $role = $role->fresh();

        $this->assertEqualsCanonicalizing([
            $allowedPermission->getKey(),
            $forbiddenPermission->getKey(),
        ], $changes['attached']);
        $this->assertTrue($role->hasPermissionTo($allowedPermission));
        $this->assertTrue($role->hasForbiddenPermission($forbiddenPermission));
        $this->assertFalse($role->hasPermissionTo($forbiddenPermission));
    }

    public function testRoleForbiddenPermissionsAreExcludedFromRolePermissionResults(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'mixed']);

        $this->app->make(PermissionContract::class)::create(['name' => 'delete-articles']);

        $role->givePermissionTo('edit-articles');
        $role->giveForbiddenTo('delete-articles');
        $this->testUser->assignRole($role);

        $permissionNames = $this->testUser->getPermissionsViaRoles()->pluck('name')->all();

        $this->assertContains('edit-articles', $permissionNames);
        $this->assertNotContains('delete-articles', $permissionNames);
        $this->assertTrue($this->testUser->hasForbiddenPermissionViaRoles('delete-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('delete-articles'));
    }

    public function testRoleForbiddenSyncAffectsAllUsersWithRoleAfterCachesAreWarm(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'publisher']);
        $role->givePermissionTo('edit-articles', 'edit-news');

        $this->testUser->assignRole($role);
        $anotherUser = User::create(['email' => 'another@example.com']);
        $anotherUser->assignRole($role);

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($anotherUser->hasPermissionTo('edit-news'));

        $role->syncPermissionsWithForbidden(
            allowed: ['edit-blog'],
            forbidden: ['edit-articles'],
        );

        $this->testUser->refresh();
        $anotherUser->refresh();

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasForbiddenPermissionViaRoles('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-blog'));
        $this->assertFalse($anotherUser->hasPermissionTo('edit-news'));
        $this->assertTrue($anotherUser->hasPermissionTo('edit-blog'));
    }

    public function testRoleGetAllPermissionsExcludesForbiddenPermissions(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'reviewer']);

        $role->givePermissionTo('edit-articles');
        $role->giveForbiddenTo('edit-news');

        $permissionNames = $role->getAllPermissions()->pluck('name')->all();

        $this->assertSame(['edit-articles'], $permissionNames);
        $this->assertTrue($role->hasForbiddenPermission('edit-news'));
    }
}
