<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Exceptions\TeamModelNotConfigured;
use Hypervel\Permission\Exceptions\TeamsNotEnabled;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Tests\Permission\Fixtures\Models\Team;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

class TeamScopeTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'permission.teams' => true,
            'permission.models.team' => Team::class,
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        $this->setUpTeams();
        app(PermissionRegistrar::class)->setTeamClass(Team::class);
        User::query()->delete();
    }

    public function testItThrowsAnExceptionWhenTeamScopesAreQueriedWhileTeamsAreNotEnabled(): void
    {
        config()->set('permission.teams', false);
        app(PermissionRegistrar::class)->teams = false;

        $this->expectException(TeamsNotEnabled::class);
        User::team(1)->get();
    }

    public function testItThrowsAnExceptionWhenWithoutTeamScopeIsQueriedWhileTeamsAreNotEnabled(): void
    {
        config()->set('permission.teams', false);
        app(PermissionRegistrar::class)->teams = false;

        $this->expectException(TeamsNotEnabled::class);
        User::withoutTeam(1)->get();
    }

    public function testItReturnsAnEmptyTeamsRelationWhenTeamsAreNotEnabled(): void
    {
        config()->set('permission.teams', false);
        app(PermissionRegistrar::class)->teams = false;

        $relation = $this->testUser->teams();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertCount(0, $relation->get());
    }

    public function testItThrowsAnExceptionWhenTeamModelIsNotConfiguredForTeamScope(): void
    {
        app(PermissionRegistrar::class)->setTeamClass(null);
        config()->set('permission.models.team', null);

        $this->expectException(TeamModelNotConfigured::class);
        User::team(1)->get();
    }

    public function testItThrowsAnExceptionWhenTeamModelIsNotConfiguredForWithoutTeamScope(): void
    {
        app(PermissionRegistrar::class)->setTeamClass(null);
        config()->set('permission.models.team', null);

        $this->expectException(TeamModelNotConfigured::class);
        User::withoutTeam(1)->get();
    }

    public function testItThrowsAnExceptionWhenTeamModelIsNotConfiguredForTeamsRelation(): void
    {
        app(PermissionRegistrar::class)->setTeamClass(null);
        config()->set('permission.models.team', null);

        $this->expectException(TeamModelNotConfigured::class);
        $this->testUser->teams()->get();
    }

    public function testItReturnsTheTeamsAUserBelongsToViaTheTeamsRelation(): void
    {
        Team::create(['id' => 1, 'name' => 'Team One']);
        Team::create(['id' => 2, 'name' => 'Team Two']);

        $user = User::create(['email' => 'user1@test.com']);

        setPermissionsTeamId(1);
        $user->assignRole('testRole');

        setPermissionsTeamId(2);
        $user->assignRole('testRole');

        $teams = $user->teams()->get();

        $this->assertCount(2, $teams);
        $this->assertEqualsCanonicalizing([1, 2], $teams->pluck('id')->all());
    }

    public function testItCanScopeUsersByTeamUsingAnId(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');
        $user2->assignRole('testRole');

        setPermissionsTeamId(2);
        $user3->assignRole('testRole');

        $this->assertCount(2, User::team(1)->get());
        $this->assertCount(1, User::team(2)->get());
    }

    public function testItCanScopeUsersByTeamUsingAnArrayOfIds(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');

        setPermissionsTeamId(2);
        $user2->assignRole('testRole');

        $this->assertCount(2, User::team([1, 2])->get());
        $this->assertCount(1, User::team([1])->get());
        $this->assertCount(1, User::team([2])->get());
    }

    public function testItCanScopeUsersByTeamUsingACollection(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');

        setPermissionsTeamId(2);
        $user2->assignRole('testRole');

        $this->assertCount(2, User::team(collect([1, 2]))->get());
        $this->assertCount(1, User::team(collect([1]))->get());
    }

    public function testItCanScopeUsersByTeamUsingAModelInstance(): void
    {
        $teamOne = Team::create(['id' => 1, 'name' => 'Team One']);
        $teamTwo = Team::create(['id' => 2, 'name' => 'Team Two']);

        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');

        setPermissionsTeamId(2);
        $user2->assignRole('testRole');

        $this->assertCount(1, User::team($teamOne)->get());
        $this->assertCount(2, User::team([$teamOne, $teamTwo])->get());
    }

    public function testItReturnsUniqueUsersWhenUserHasMultipleRolesInTheSameTeam(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        setPermissionsTeamId(1);
        $user->assignRole('testRole');
        $user->assignRole('testRole2');

        $this->assertCount(1, User::team(1)->get());
    }

    public function testItCanScopeUsersWithoutATeamUsingAnId(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');
        $user2->assignRole('testRole');

        setPermissionsTeamId(2);
        $user3->assignRole('testRole');

        $this->assertCount(1, User::withoutTeam(1)->get());
        $this->assertCount(2, User::withoutTeam(2)->get());
    }

    public function testItCanScopeUsersWithoutATeamUsingAnArrayOfIds(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');

        setPermissionsTeamId(2);
        $user2->assignRole('testRole');

        $this->assertCount(1, User::withoutTeam([1, 2])->get());
        $this->assertCount(2, User::withoutTeam([1])->get());
    }

    public function testItCanScopeUsersWithoutATeamUsingACollection(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');

        setPermissionsTeamId(2);
        $user2->assignRole('testRole');

        $this->assertCount(1, User::withoutTeam(collect([1, 2]))->get());
        $this->assertCount(2, User::withoutTeam(collect([1]))->get());
    }

    public function testItDoesNotMixUpUsersFromDifferentTeams(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole('testRole');
        $user1->assignRole('testRole2');

        setPermissionsTeamId(2);
        $user2->assignRole('testRole');

        $inTeam1 = User::team(1)->get();
        $inTeam2 = User::team(2)->get();

        $this->assertCount(1, $inTeam1);
        $this->assertSame($user1->id, $inTeam1->first()->id);
        $this->assertCount(1, $inTeam2);
        $this->assertSame($user2->id, $inTeam2->first()->id);
    }
}
