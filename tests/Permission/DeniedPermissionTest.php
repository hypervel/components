<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\PermissionDoesNotExist;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Tests\Permission\Fixtures\Models\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\Role;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class DeniedPermissionTest extends TestCase
{
    public function testDirectDeniedPermissionDeniesDirectAndNormalPermissionChecks(): void
    {
        $this->testUser->denyPermissionTo('edit-articles');

        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue((bool) $this->testUser->permissions->firstWhere('name', 'edit-articles')->pivot->getAttribute('is_denied'));
    }

    public function testDirectDeniedPermissionFlipsExistingAllowedPermission(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->denyPermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertSame([], $this->testUser->getPermissionNames()->all());
    }

    public function testDirectAllowedPermissionFlipsExistingDeniedPermission(): void
    {
        $this->testUser->denyPermissionTo('edit-articles');
        $this->testUser->givePermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertSame(['edit-articles'], $this->testUser->getPermissionNames()->all());
    }

    public function testDirectDeniedPermissionOverridesRolePermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->testUser->assignRole('testRole');
        $this->testUser->denyPermissionTo('edit-articles');

        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->getAllPermissions()->contains('name', 'edit-articles'));
    }

    public function testDirectDeniedPermissionIsGuardExact(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);
        $webPermission = $permissionClass::findByName('edit-articles', 'web');
        $apiPermission = $permissionClass::create(['name' => 'edit-articles', 'guard_name' => 'api']);

        $this->testUser->givePermissionTo($webPermission);
        $this->testUser->denyPermissionTo($apiPermission);

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles', 'api'));
        $this->assertFalse($this->testUser->hasPermissionTo($apiPermission));
        $this->assertTrue($this->testUser->hasPermissionTo($webPermission));
    }

    public function testRoleDeniedPermissionOverridesDirectPermission(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'restricted']);
        $role->denyPermissionTo('edit-articles');

        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->assignRole($role);

        $this->assertTrue($this->testUser->hasDeniedPermissionViaRoles('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUser->getAllPermissions()->contains('name', 'edit-articles'));
    }

    public function testRoleDeniedPermissionFlipsExistingAllowedPermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUserRole->denyPermissionTo('edit-articles');

        $this->testUserRole->refresh();

        $this->assertSame(1, $this->testUserRole->permissions()->count());
        $this->assertTrue($this->testUserRole->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testRoleAllowedPermissionFlipsExistingDeniedPermission(): void
    {
        $this->testUserRole->denyPermissionTo('edit-articles');
        $this->testUserRole->givePermissionTo('edit-articles');

        $this->testUserRole->refresh();

        $this->assertSame(1, $this->testUserRole->permissions()->count());
        $this->assertFalse($this->testUserRole->hasDeniedPermission('edit-articles'));
        $this->assertTrue($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testRoleDeniedPermissionOverridesAllowedRolePermission(): void
    {
        $allowedRole = $this->app->make(RoleContract::class)::create(['name' => 'allowed']);
        $deniedRole = $this->app->make(RoleContract::class)::create(['name' => 'denied']);

        $allowedRole->givePermissionTo('edit-articles');
        $deniedRole->denyPermissionTo('edit-articles');
        $this->testUser->assignRole($allowedRole, $deniedRole);

        $this->assertTrue($this->testUser->hasDeniedPermissionViaRoles('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->getPermissionsViaRoles()->contains('name', 'edit-articles'));
        $this->assertFalse($this->testUser->getAllPermissions()->contains('name', 'edit-articles'));
    }

    public function testRoleDeniedPermissionIsGuardExact(): void
    {
        $permissionClass = $this->app->make(PermissionContract::class);
        $roleClass = $this->app->make(RoleContract::class);
        $webPermission = $permissionClass::findByName('edit-articles', 'web');
        $apiPermission = $permissionClass::create(['name' => 'edit-articles', 'guard_name' => 'api']);
        $webRole = $roleClass::create(['name' => 'web-editor']);
        $apiRole = $roleClass::create(['name' => 'api-blocked', 'guard_name' => 'api']);

        $webRole->givePermissionTo($webPermission);
        $apiRole->denyPermissionTo($apiPermission);
        $this->testUser->assignRole($webRole, $apiRole);
        $webPermission = $permissionClass::findByName('edit-articles', 'web');

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles', 'api'));
        $this->assertFalse($this->testUser->hasPermissionTo($apiPermission));
        $this->assertTrue($this->testUser->hasPermissionTo($webPermission));
    }

    public function testRoleDeniedPermissionForDifferentPermissionDoesNotDenyRequestedPermission(): void
    {
        $allowedRole = $this->app->make(RoleContract::class)::create(['name' => 'allowed-editor']);
        $deniedRole = $this->app->make(RoleContract::class)::create(['name' => 'blocked-news']);

        $allowedRole->givePermissionTo('edit-articles');
        $deniedRole->denyPermissionTo('edit-news');
        $this->testUser->assignRole($allowedRole, $deniedRole);

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-news'));
    }

    public function testMissingPermissionStillThrowsOrChecksFalseWithRoleDeniedEdges(): void
    {
        $this->testUserRole->denyPermissionTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);

        $this->assertFalse($this->testUser->checkPermissionTo('missing-permission'));

        $this->expectException(PermissionDoesNotExist::class);

        $this->testUser->hasPermissionTo('missing-permission');
    }

    public function testRoleModelDeniedPermissionIsMatchedByConcretePermissionGuard(): void
    {
        $permission = $this->app->make(PermissionContract::class)::create([
            'name' => 'api-edit-articles',
            'guard_name' => 'api',
        ]);
        $role = $this->app->make(RoleContract::class)::create([
            'name' => 'api-editor',
            'guard_name' => 'api',
        ]);

        $role->denyPermissionTo($permission);

        $this->assertFalse($role->hasPermissionTo($permission));
    }

    public function testDeniedPermissionWinsWhenAllowedAndDeniedAreSyncedTogether(): void
    {
        $changes = $this->testUser->syncPermissionEffects(
            allowed: ['edit-articles', 'edit-news'],
            denied: ['edit-news'],
        );

        $this->assertArrayHasKey('attached', $changes);
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-news'));
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-news'));
    }

    public function testDeniedSyncReplacesExistingDirectPermissions(): void
    {
        $this->app->make(PermissionContract::class)::create(['name' => 'delete-articles']);

        $this->testUser->givePermissionTo('edit-articles', 'edit-news');
        $changes = $this->testUser->syncPermissionEffects(
            allowed: ['edit-news'],
            denied: ['delete-articles'],
        );

        $this->testUser->refresh();

        $this->assertEqualsCanonicalizing([
            $this->testUserPermission->getKey(),
        ], $changes['detached']);
        $this->assertEqualsCanonicalizing([
            $this->app->make(PermissionContract::class)::findByName('delete-articles')->getKey(),
        ], $changes['attached']);
        $this->assertSame([], $changes['updated']);
        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasDirectPermission('edit-news'));
        $this->assertTrue($this->testUser->hasDeniedPermission('delete-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('delete-articles'));
    }

    public function testDeniedSyncReportsPivotChangesAsUpdates(): void
    {
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->denyPermissionTo('edit-news');

        $changes = $this->testUser->syncPermissionEffects(
            allowed: ['edit-news'],
            denied: ['edit-articles'],
        );

        $this->testUser->refresh();

        $this->assertSame([], $changes['attached']);
        $this->assertSame([], $changes['detached']);
        $this->assertEqualsCanonicalizing([
            $this->testUserPermission->getKey(),
            $this->app->make(PermissionContract::class)::findByName('edit-news')->getKey(),
        ], $changes['updated']);
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-news'));
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-news'));
    }

    public function testQueuedDeniedSyncReplacesEarlierQueuedAssignmentsWhenModelIsSaved(): void
    {
        $user = new User(['email' => 'queued-denied-sync@example.com']);

        $user->givePermissionTo('edit-articles');
        $changes = $user->syncPermissionEffects(
            allowed: ['edit-blog', 'edit-news'],
            denied: ['edit-news'],
        );

        $this->assertSame(['attached' => [], 'detached' => [], 'updated' => []], $changes);

        $user->save();
        $user->refresh();

        $this->assertSame(2, $user->permissions()->count());
        $this->assertFalse($user->hasPermissionTo('edit-articles'));
        $this->assertTrue($user->hasPermissionTo('edit-blog'));
        $this->assertFalse($user->hasPermissionTo('edit-news'));
        $this->assertTrue($user->hasDeniedPermission('edit-news'));
    }

    public function testQueuedDirectDeniedPermissionFlipsExistingQueuedAllowedPermission(): void
    {
        $user = new User(['email' => 'queued@example.com']);

        $user->givePermissionTo('edit-articles');
        $user->denyPermissionTo('edit-articles');
        $user->save();

        $user->refresh();

        $this->assertSame(1, $user->permissions()->count());
        $this->assertTrue($user->hasDeniedPermission('edit-articles'));
        $this->assertFalse($user->hasPermissionTo('edit-articles'));
    }

    public function testQueuedDirectAllowedPermissionFlipsExistingQueuedDeniedPermission(): void
    {
        $user = new User(['email' => 'queued@example.com']);

        $user->denyPermissionTo('edit-articles');
        $user->givePermissionTo('edit-articles');
        $user->save();

        $user->refresh();

        $this->assertSame(1, $user->permissions()->count());
        $this->assertFalse($user->hasDeniedPermission('edit-articles'));
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
    }

    public function testRevokePermissionRemovesDirectDeniedPermission(): void
    {
        $this->testUser->denyPermissionTo('edit-articles');
        $this->testUser->revokePermissionTo('edit-articles');

        $this->testUser->refresh();

        $this->assertSame(0, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasDeniedPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testRemovingDirectDeniedPermissionRevealsRoleAllowedPermission(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->denyPermissionTo('edit-articles');

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));

        $this->testUser->revokePermissionTo('edit-articles');

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testRoleDeniedSyncUsesCustomPermissionPrimaryKeys(): void
    {
        $this->setUpCustomModels();

        $allowedPermission = Permission::findOrCreate('custom-allow');
        $deniedPermission = Permission::findOrCreate('custom-deny');
        $role = Role::findOrCreate('custom-role');

        $changes = $role->syncPermissionEffects(
            allowed: [$allowedPermission],
            denied: [$deniedPermission],
        );

        $role = $role->fresh();

        $this->assertEqualsCanonicalizing([
            $allowedPermission->getKey(),
            $deniedPermission->getKey(),
        ], $changes['attached']);
        $this->assertTrue($role->hasPermissionTo($allowedPermission));
        $this->assertTrue($role->hasDeniedPermission($deniedPermission));
        $this->assertFalse($role->hasPermissionTo($deniedPermission));
    }

    public function testRoleDeniedPermissionsAreExcludedFromRolePermissionResults(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'mixed']);

        $this->app->make(PermissionContract::class)::create(['name' => 'delete-articles']);

        $role->givePermissionTo('edit-articles');
        $role->denyPermissionTo('delete-articles');
        $this->testUser->assignRole($role);

        $permissionNames = $this->testUser->getPermissionsViaRoles()->pluck('name')->all();

        $this->assertContains('edit-articles', $permissionNames);
        $this->assertNotContains('delete-articles', $permissionNames);
        $this->assertTrue($this->testUser->hasDeniedPermissionViaRoles('delete-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('delete-articles'));
    }

    public function testDuplicateRoleGrantedPermissionsAreReturnedOnce(): void
    {
        $firstRole = $this->app->make(RoleContract::class)::create(['name' => 'first-editor']);
        $secondRole = $this->app->make(RoleContract::class)::create(['name' => 'second-editor']);

        $firstRole->givePermissionTo('edit-articles');
        $secondRole->givePermissionTo('edit-articles');
        $this->testUser->assignRole($firstRole, $secondRole);

        $this->assertSame(
            ['edit-articles'],
            $this->testUser->getPermissionsViaRoles()->pluck('name')->values()->all(),
        );
    }

    public function testDeniedDuplicateRolePermissionIsExcluded(): void
    {
        $allowedRole = $this->app->make(RoleContract::class)::create(['name' => 'duplicate-allowed']);
        $deniedRole = $this->app->make(RoleContract::class)::create(['name' => 'duplicate-denied']);

        $allowedRole->givePermissionTo('edit-articles');
        $deniedRole->denyPermissionTo('edit-articles');
        $this->testUser->assignRole($allowedRole, $deniedRole);

        $this->assertSame([], $this->testUser->getPermissionsViaRoles()->pluck('name')->values()->all());
    }

    public function testRoleDeniedSyncAffectsAllUsersWithRoleAfterCachesAreWarm(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'publisher']);
        $role->givePermissionTo('edit-articles', 'edit-news');

        $this->testUser->assignRole($role);
        $anotherUser = User::create(['email' => 'another@example.com']);
        $anotherUser->assignRole($role);

        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($anotherUser->hasPermissionTo('edit-news'));

        $role->syncPermissionEffects(
            allowed: ['edit-blog'],
            denied: ['edit-articles'],
        );

        $this->testUser->refresh();
        $anotherUser->refresh();

        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertTrue($this->testUser->hasDeniedPermissionViaRoles('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-blog'));
        $this->assertFalse($anotherUser->hasPermissionTo('edit-news'));
        $this->assertTrue($anotherUser->hasPermissionTo('edit-blog'));
    }

    public function testRoleGetAllPermissionsExcludesDeniedPermissions(): void
    {
        $role = $this->app->make(RoleContract::class)::create(['name' => 'reviewer']);

        $role->givePermissionTo('edit-articles');
        $role->denyPermissionTo('edit-news');

        $permissionNames = $role->getAllPermissions()->pluck('name')->all();

        $this->assertSame(['edit-articles'], $permissionNames);
        $this->assertTrue($role->hasDeniedPermission('edit-news'));
    }

    public function testDirectPermissionChecksDenyWhenRelationContainsDuplicateEffects(): void
    {
        $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');
        $allowed = clone $permission;
        $denied = clone $permission;

        $allowed->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUser,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                Config::morphKey() => $this->testUser->getKey(),
                'model_type' => $this->testUser->getMorphClass(),
                'is_denied' => false,
            ],
            Config::modelHasPermissionsTable(),
            true,
        ));

        $denied->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUser,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                Config::morphKey() => $this->testUser->getKey(),
                'model_type' => $this->testUser->getMorphClass(),
                'is_denied' => true,
            ],
            Config::modelHasPermissionsTable(),
            true,
        ));

        $this->testUser->setRelation('permissions', collect([$allowed, $denied]));

        $this->assertFalse($this->testUser->hasDirectPermission('edit-articles'));
    }

    public function testRolePermissionChecksDenyWhenRelationContainsDuplicateEffects(): void
    {
        $permission = $this->app->make(PermissionContract::class)::findByName('edit-articles');
        $allowed = clone $permission;
        $denied = clone $permission;

        $allowed->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUserRole,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                $this->app->make(PermissionRegistrar::class)->pivotRole => $this->testUserRole->getKey(),
                'is_denied' => false,
            ],
            Config::roleHasPermissionsTable(),
            true,
        ));

        $denied->setRelation('pivot', Pivot::fromRawAttributes(
            $this->testUserRole,
            [
                $this->app->make(PermissionRegistrar::class)->pivotPermission => $permission->getKey(),
                $this->app->make(PermissionRegistrar::class)->pivotRole => $this->testUserRole->getKey(),
                'is_denied' => true,
            ],
            Config::roleHasPermissionsTable(),
            true,
        ));

        $this->testUserRole->setRelation('permissions', collect([$allowed, $denied]));

        $this->assertFalse($this->testUserRole->hasDirectPermission('edit-articles'));
        $this->assertFalse($this->testUserRole->hasPermissionTo('edit-articles'));
    }

    public function testPermissionScopeExcludesDirectDeniedPermission(): void
    {
        $this->testUser->denyPermissionTo('edit-articles');

        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeExcludesRoleDeniedPermission(): void
    {
        $this->testUserRole->denyPermissionTo('edit-articles');
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
        $this->testUser->denyPermissionTo('edit-articles');

        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeLetsRoleDenyOverrideDirectAllow(): void
    {
        $this->testUserRole->denyPermissionTo('edit-articles');
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
        $this->testUser->denyPermissionTo('edit-news');

        $this->assertTrue(User::permission(['edit-articles', 'edit-news'])->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeMatchesSecondAllowedPermissionWhenFirstRequestedPermissionIsDenied(): void
    {
        $this->testUserRole->denyPermissionTo('edit-articles');
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

    public function testRolePermissionScopeExcludesDeniedRolePermissionEdges(): void
    {
        $this->testUserRole->denyPermissionTo('edit-articles');

        $this->assertFalse($this->app->make(RoleContract::class)::permission('edit-articles')->get()->contains(
            fn ($role): bool => $role->is($this->testUserRole),
        ));
    }
}
