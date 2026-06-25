<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
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
}
