<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
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

    public function testDirectForbiddenPermissionFlipsExistingAllowedPermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertSame([], $this->testUser->getPermissionNames()->all());
    }

    public function testDirectAllowedPermissionFlipsExistingForbiddenPermission(): void
    {
        $this->testUser->giveForbiddenTo('edit-articles');
        $this->testUser->givePermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertSame(['edit-articles'], $this->testUser->getPermissionNames()->all());
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

    public function testRoleForbiddenPermissionFlipsExistingAllowedPermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUserRole->giveForbiddenTo('edit-articles');

        $this->testUserRole->refresh();

        $this->assertSame(1, $this->testUserRole->permissions()->count());
        $this->assertTrue($this->testUserRole->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testRoleAllowedPermissionFlipsExistingForbiddenPermission(): void
    {
        $this->testUserRole->giveForbiddenTo('edit-articles');
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->testUserRole->refresh();

        $this->assertSame(1, $this->testUserRole->permissions()->count());
        $this->assertFalse($this->testUserRole->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testRoleForbiddenPermissionOverridesAllowedRolePermission(): void
    {
        $allowedRole = $this->app->make(RoleContract::class)::create(['name' => 'allowed']);
        $forbiddenRole = $this->app->make(RoleContract::class)::create(['name' => 'forbidden']);

        $allowedRole->givePermissionTo('edit-articles');
        $forbiddenRole->giveForbiddenTo('edit-articles');
        $this->testUser->assignRole($allowedRole, $forbiddenRole);

        $this->assertTrue($this->testUser->hasForbiddenPermissionViaRoles('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->getPermissionsViaRoles()->contains('name', 'edit-articles'));
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

    public function testQueuedDirectForbiddenPermissionFlipsExistingQueuedAllowedPermission(): void
    {
        $user = new User(['email' => 'queued@example.com']);

        $user->givePermissionTo('edit-articles');
        $user->giveForbiddenTo('edit-articles');
        $user->save();

        $user->refresh();

        $this->assertSame(1, $user->permissions()->count());
        $this->assertTrue($user->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($user->hasPermissionTo('edit-articles'));
    }

    public function testQueuedDirectAllowedPermissionFlipsExistingQueuedForbiddenPermission(): void
    {
        $user = new User(['email' => 'queued@example.com']);

        $user->giveForbiddenTo('edit-articles');
        $user->givePermissionTo('edit-articles');
        $user->save();

        $user->refresh();

        $this->assertSame(1, $user->permissions()->count());
        $this->assertFalse($user->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
    }

    public function testRevokePermissionRemovesDirectForbiddenPermission(): void
    {
        $this->testUser->giveForbiddenTo('edit-articles');
        $this->testUser->revokePermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(0, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testRemovingDirectForbiddenPermissionRevealsRoleAllowedPermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

        $this->testUser->revokePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
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

    public function testDirectPermissionChecksDenyWhenRelationContainsDuplicateEffects(): void
    {
        $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');
        $allowed = clone $permission;
        $forbidden = clone $permission;

        $allowed->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUser,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                Config::morphKey() => $this->testUser->getKey(),
                'model_type' => $this->testUser->getMorphClass(),
                'is_forbidden' => false,
            ],
            Config::modelHasPermissionsTable(),
            true,
        ));

        $forbidden->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUser,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                Config::morphKey() => $this->testUser->getKey(),
                'model_type' => $this->testUser->getMorphClass(),
                'is_forbidden' => true,
            ],
            Config::modelHasPermissionsTable(),
            true,
        ));

        $this->testUser->setRelation('permissions', collect([$allowed, $forbidden]));

        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
    }

    public function testRolePermissionChecksDenyWhenRelationContainsDuplicateEffects(): void
    {
        $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');
        $allowed = clone $permission;
        $forbidden = clone $permission;

        $allowed->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUserRole,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                $this->app->make(PermissionRegistrar::class)->pivotRole => $this->testUserRole->getKey(),
                'is_forbidden' => false,
            ],
            Config::roleHasPermissionsTable(),
            true,
        ));

        $forbidden->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUserRole,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                $this->app->make(PermissionRegistrar::class)->pivotRole => $this->testUserRole->getKey(),
                'is_forbidden' => true,
            ],
            Config::roleHasPermissionsTable(),
            true,
        ));

        $this->testUserRole->setRelation('permissions', collect([$allowed, $forbidden]));

        $this->assertFalse($this->testUserRole->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testPermissionScopeExcludesDirectForbiddenPermission(): void
    {
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeExcludesRoleForbiddenPermission(): void
    {
        $this->testUserRole->giveForbiddenTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);

        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeLetsDirectDenyOverrideRoleAllow(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->giveForbiddenTo('edit-articles');

        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeLetsRoleDenyOverrideDirectAllow(): void
    {
        $this->testUserRole->giveForbiddenTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->givePermissionTo('edit-articles');

        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeMatchesAllowedPermissionWhenDifferentRequestedPermissionIsDenied(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->giveForbiddenTo('edit-news');

        $this->assertTrue(User::permission(['edit-articles', 'edit-news'])->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeMatchesSecondAllowedPermissionWhenFirstRequestedPermissionIsDenied(): void
    {
        $this->testUserRole->giveForbiddenTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->givePermissionTo('edit-news');

        $this->assertTrue(User::permission(['edit-articles', 'edit-news'])->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeWithNoPermissionsMatchesNoModels(): void
    {
        $this->assertFalse(User::permission([])->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testWithoutPermissionScopeWithNoPermissionsMatchesAllModels(): void
    {
        $this->assertTrue(User::withoutPermission([])->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testRolePermissionScopeExcludesForbiddenRolePermissionEdges(): void
    {
        $this->testUserRole->giveForbiddenTo('edit-articles');

        $this->assertFalse($this->app->make(RoleContract::class)::permission('edit-articles')->get()->contains(
            fn ($role): bool => $role->is($this->testUserRole),
        ));
    }
}
