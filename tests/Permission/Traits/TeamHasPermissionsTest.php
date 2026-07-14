<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class TeamHasPermissionsTest extends HasPermissionsTest
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.teams', true);
    }

    protected function setUpInCoroutine(): void
    {
        $this->setUpTeams();
    }

    public function testItCanAssignSameAndDifferentPermissionsOnSameUserOnDifferentTeams(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->givePermissionTo('edit-articles', 'edit-news');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles', 'edit-blog');

        setPermissionsTeamId(1);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());
        $this->assertTrue($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-news']));
        $this->assertFalse($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-blog']));

        setPermissionsTeamId(2);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-blog'], $this->testUser->getPermissionNames()->sort()->values()->all());
        $this->assertTrue($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-blog']));
        $this->assertFalse($this->testUser->hasAllDirectPermissions(['edit-articles', 'edit-news']));
    }

    public function testItCanListAllCoupledPermissionsDirectlyAndViaRolesOnSameUserOnDifferentTeams(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-news');

        setPermissionsTeamId(2);
        $this->testUser->assignRole('testRole');
        $this->testUser->givePermissionTo('edit-blog');

        setPermissionsTeamId(1);
        $this->testUser->load('roles', 'permissions');
        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getAllPermissions()->pluck('name')->sort()->values()->all());

        setPermissionsTeamId(2);
        $this->testUser->load('roles', 'permissions');
        $this->assertSame(['edit-articles', 'edit-blog'], $this->testUser->getAllPermissions()->pluck('name')->sort()->values()->all());
    }

    public function testWarmAuthorizationReusesHydratedCatalogRelationsAcrossTeams(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        setPermissionsTeamId(1);
        $this->testUser->assignRole($this->testUserRole);

        setPermissionsTeamId(2);
        $this->testUser->assignRole($this->testUserRole);

        setPermissionsTeamId(1);
        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->assertSame(
            [$this->testUserPermission->name],
            $this->testUser->getAllPermissions()->pluck('name')->all(),
        );

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->testUser->getAllPermissions();
        $this->assertSame([], DB::getQueryLog());

        setPermissionsTeamId(2);
        DB::flushQueryLog();

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->testUser->getAllPermissions();
        $this->assertCount(2, DB::getQueryLog());

        DB::flushQueryLog();

        $this->assertTrue($this->testUser->hasPermissionTo($this->testUserPermission));
        $this->testUser->getAllPermissions();
        $this->assertSame([], DB::getQueryLog());
    }

    public function testItCanSyncOrRemovePermissionsWithoutDetachingDifferentTeams(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->syncPermissions('edit-articles', 'edit-news');

        setPermissionsTeamId(2);
        $this->testUser->syncPermissions('edit-articles', 'edit-blog');

        setPermissionsTeamId(1);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());

        $this->testUser->revokePermissionTo('edit-articles');
        $this->assertSame(['edit-news'], $this->testUser->getPermissionNames()->sort()->values()->all());

        setPermissionsTeamId(2);
        $this->testUser->load('permissions');
        $this->assertSame(['edit-articles', 'edit-blog'], $this->testUser->getPermissionNames()->sort()->values()->all());
    }

    public function testItCanScopeUsersOnDifferentTeams(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(2);
        $user1->givePermissionTo(['edit-articles', 'edit-news']);
        $this->testUserRole->givePermissionTo('edit-articles');
        $user2->assignRole('testRole');

        setPermissionsTeamId(1);
        $user1->givePermissionTo(['edit-articles']);

        setPermissionsTeamId(2);
        $this->assertCount(2, User::permission(['edit-articles', 'edit-news'])->get());
        $this->assertCount(1, User::permission('edit-news')->get());

        setPermissionsTeamId(1);
        $this->assertCount(1, User::permission(['edit-articles', 'edit-news'])->get());
        $this->assertCount(0, User::permission('edit-news')->get());
    }

    public function testForbiddenPermissionFlipsExistingAllowedPermissionForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->givePermissionTo('edit-articles');
        $this->testUser->giveForbiddenTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertSame([], $this->testUser->getPermissionNames()->all());

        setPermissionsTeamId(2);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));
        $this->assertSame(['edit-articles'], $this->testUser->getPermissionNames()->all());
    }

    public function testAllowedPermissionFlipsExistingForbiddenPermissionForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->giveForbiddenTo('edit-articles');
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->giveForbiddenTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertTrue($this->testUser->hasPermissionTo('edit-articles'));

        setPermissionsTeamId(2);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($this->testUser->hasPermissionTo('edit-articles'));
    }

    public function testQueuedPermissionAssignmentsKeepSeparateTeamEdges(): void
    {
        $user = new User(['email' => 'queued-teams@example.com']);

        setPermissionsTeamId(1);
        $user->givePermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $user->givePermissionTo('edit-articles');

        $user->save();

        setPermissionsTeamId(1);
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertSame(1, $user->permissions()->count());

        setPermissionsTeamId(2);
        $user->unsetRelation('permissions');
        $this->assertTrue($user->hasPermissionTo('edit-articles'));
        $this->assertSame(1, $user->permissions()->count());
    }

    public function testQueuedSyncPermissionsReplacesOnlyCurrentTeamPermissionAssignments(): void
    {
        $user = new User(['email' => 'queued-team-sync@example.com']);

        setPermissionsTeamId(1);
        $user->givePermissionTo('edit-news');

        setPermissionsTeamId(2);
        $user->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $user->syncPermissions('edit-blog');

        $user->save();

        setPermissionsTeamId(1);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-blog'], $user->getPermissionNames()->all());
        $this->assertFalse($user->hasPermissionTo('edit-news'));

        setPermissionsTeamId(2);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-articles'], $user->getPermissionNames()->all());
    }

    public function testQueuedForbiddenSyncReplacesOnlyCurrentTeamPermissionAssignments(): void
    {
        $user = new User(['email' => 'queued-team-forbidden-sync@example.com']);

        setPermissionsTeamId(1);
        $user->givePermissionTo('edit-news');

        setPermissionsTeamId(2);
        $user->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $changes = $user->syncPermissionsWithForbidden(
            allowed: ['edit-blog'],
            forbidden: ['edit-articles'],
        );

        $this->assertSame(['attached' => [], 'detached' => [], 'updated' => []], $changes);

        $user->save();

        setPermissionsTeamId(1);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-blog'], $user->getPermissionNames()->all());
        $this->assertTrue($user->hasForbiddenPermission('edit-articles'));
        $this->assertFalse($user->hasPermissionTo('edit-news'));

        setPermissionsTeamId(2);
        $user->unsetRelation('permissions');
        $this->assertSame(['edit-articles'], $user->getPermissionNames()->all());
        $this->assertFalse($user->hasForbiddenPermission('edit-articles'));
    }

    public function testItRevokesForbiddenPermissionsForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->giveForbiddenTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->giveForbiddenTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->revokePermissionTo('edit-articles');
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(0, $this->testUser->permissions()->count());
        $this->assertFalse($this->testUser->hasForbiddenPermission('edit-articles'));

        setPermissionsTeamId(2);
        $this->testUser->unsetRelation('permissions');
        $this->assertSame(1, $this->testUser->permissions()->count());
        $this->assertTrue($this->testUser->hasForbiddenPermission('edit-articles'));
    }

    public function testPermissionScopeUsesDirectPermissionEffectForCurrentTeamOnly(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->giveForbiddenTo('edit-articles');

        setPermissionsTeamId(2);
        $this->testUser->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertTrue(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));

        setPermissionsTeamId(2);
        $this->assertTrue(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
        $this->assertFalse(User::withoutPermission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }

    public function testPermissionScopeUsesRoleAssignmentsForCurrentTeamOnly(): void
    {
        $this->testUserRole->givePermissionTo('edit-articles');

        setPermissionsTeamId(1);
        $this->testUser->assignRole($this->testUserRole);

        $this->assertTrue(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));

        setPermissionsTeamId(2);
        $this->assertFalse(User::permission('edit-articles')->get()->contains(
            fn (User $user): bool => $user->is($this->testUser),
        ));
    }
}
