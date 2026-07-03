<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Contracts\Role;
use Hypervel\Permission\Exceptions\RoleDoesNotExist;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class TeamHasRolesTest extends HasRolesTest
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

    public function testItDoesNotRunUnnecessarySqlWhenAssigningNewRoles(): void
    {
        $role2 = app(Role::class)->where('name', 'testRole2')->first();

        DB::enableQueryLog();
        $this->testUser->syncRoles($this->testUserRole, $role2);
        DB::disableQueryLog();

        // Hypervel's team-aware sync path writes the current team pivot directly,
        // so it avoids the extra relation reload that Spatie needs under Laravel.
        $this->assertCount(2, DB::getQueryLog());
    }

    public function testItDeletesPivotTableEntriesWhenDeletingModelsAcrossTeams(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');
        $user1->givePermissionTo('edit-articles');
        $user2->assignRole('testRole');
        $user2->givePermissionTo('edit-articles');

        setPermissionsTeamId(2);
        $user1->givePermissionTo('edit-news');

        $this->assertDatabaseHas('model_has_permissions', ['model_test_id' => $user1->getKey()]);
        $this->assertDatabaseHas('model_has_roles', ['model_test_id' => $user1->getKey()]);

        $user1->delete();

        setPermissionsTeamId(1);
        $this->assertDatabaseMissing('model_has_permissions', ['model_test_id' => $user1->getKey()]);
        $this->assertDatabaseMissing('model_has_roles', ['model_test_id' => $user1->getKey()]);
        $this->assertDatabaseHas('model_has_permissions', ['model_test_id' => $user2->getKey()]);
        $this->assertDatabaseHas('model_has_roles', ['model_test_id' => $user2->getKey()]);
    }

    public function testItCanAssignSameAndDifferentRolesOnSameUserDifferentTeams(): void
    {
        app(Role::class)->create(['name' => 'testRole3']);
        app(Role::class)->create(['name' => 'testRole3', 'team_test_id' => 2]);
        app(Role::class)->create(['name' => 'testRole4', 'team_test_id' => null]);

        $testRole3Team1 = app(Role::class)->where(['name' => 'testRole3', 'team_test_id' => 1])->first();
        $testRole3Team2 = app(Role::class)->where(['name' => 'testRole3', 'team_test_id' => 2])->first();
        $testRole4NoTeam = app(Role::class)->where(['name' => 'testRole4', 'team_test_id' => null])->first();

        $this->assertNotNull($testRole3Team1);
        $this->assertNotNull($testRole3Team2);
        $this->assertNotNull($testRole4NoTeam);

        setPermissionsTeamId(1);
        $this->testUser->assignRole('testRole', 'testRole2');
        $this->testUser->load('roles');

        setPermissionsTeamId(2);
        $this->testUser->assignRole('testRole', 'testRole3');

        setPermissionsTeamId(1);
        $this->testUser->load('roles');

        $this->assertSame(['testRole', 'testRole2'], $this->testUser->getRoleNames()->sort()->values()->all());
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'testRole2']));

        $this->testUser->assignRole('testRole3', 'testRole4');
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'testRole2', 'testRole3', 'testRole4']));
        $this->assertTrue($this->testUser->hasRole($testRole3Team1));
        $this->assertTrue($this->testUser->hasRole($testRole4NoTeam));

        setPermissionsTeamId(2);
        $this->testUser->load('roles');

        $this->assertSame(['testRole', 'testRole3'], $this->testUser->getRoleNames()->sort()->values()->all());
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'testRole3']));
        $this->assertTrue($this->testUser->hasRole($testRole3Team2));

        $this->testUser->assignRole('testRole4');
        $this->assertTrue($this->testUser->hasExactRoles(['testRole', 'testRole3', 'testRole4']));
        $this->assertTrue($this->testUser->hasRole($testRole4NoTeam));
    }

    public function testRoleLookupFindsGlobalAndCurrentTeamRolesOnly(): void
    {
        app(Role::class)->create(['name' => 'global-role', 'team_test_id' => null]);
        app(Role::class)->create(['name' => 'team-one-role', 'team_test_id' => 1]);
        app(Role::class)->create(['name' => 'team-two-role', 'team_test_id' => 2]);

        setPermissionsTeamId(1);

        $this->assertSame('global-role', app(Role::class)::findByName('global-role')->name);
        $this->assertSame('team-one-role', app(Role::class)::findByName('team-one-role')->name);

        try {
            app(Role::class)::findByName('team-two-role');
            $this->fail('Expected missing team role exception was not thrown.');
        } catch (RoleDoesNotExist) {
            $this->assertTrue(true);
        }

        setPermissionsTeamId(2);

        $this->assertSame('team-two-role', app(Role::class)::findByName('team-two-role')->name);
    }

    public function testRoleLookupUsesFirstCatalogMatchForGlobalAndCurrentTeamRoleWithSameName(): void
    {
        app(Role::class)->create(['name' => 'shared-role', 'team_test_id' => null]);
        DB::table(Config::rolesTable())->insert([
            'name' => 'shared-role',
            'guard_name' => 'web',
            'team_test_id' => 1,
        ]);

        setPermissionsTeamId(1);

        $matches = app(PermissionRegistrar::class)->getRoles(['name' => 'shared-role', 'guard_name' => 'web']);

        $this->assertCount(2, $matches);
        $this->assertTrue($matches->first()->is(app(Role::class)::findByName('shared-role')));
    }

    public function testItCanSyncOrRemoveRolesWithoutDetachingDifferentTeams(): void
    {
        app(Role::class)->create(['name' => 'testRole3', 'team_test_id' => 2]);

        setPermissionsTeamId(1);
        $this->testUser->syncRoles('testRole', 'testRole2');

        setPermissionsTeamId(2);
        $this->testUser->syncRoles('testRole', 'testRole3');

        setPermissionsTeamId(1);
        $this->testUser->load('roles');

        $this->assertSame(['testRole', 'testRole2'], $this->testUser->getRoleNames()->sort()->values()->all());

        $this->testUser->removeRole('testRole');
        $this->assertSame(['testRole2'], $this->testUser->getRoleNames()->sort()->values()->all());

        setPermissionsTeamId(2);
        $this->testUser->load('roles');

        $this->assertSame(['testRole', 'testRole3'], $this->testUser->getRoleNames()->sort()->values()->all());
    }

    public function testItCanScopeUsersOnDifferentTeams(): void
    {
        User::all()->each(fn ($item) => $item->delete());
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(2);
        $user1->assignRole($this->testUserRole);
        $user2->assignRole('testRole2');

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');

        setPermissionsTeamId(2);
        $this->assertCount(1, User::role($this->testUserRole)->get());
        $this->assertCount(2, User::role(['testRole', 'testRole2'])->get());
        $this->assertCount(1, User::withoutRole('testRole')->get());

        setPermissionsTeamId(1);
        $this->assertCount(1, User::role($this->testUserRole)->get());
        $this->assertCount(0, User::role('testRole2')->get());
        $this->assertCount(1, User::withoutRole('testRole')->get());
    }
}
