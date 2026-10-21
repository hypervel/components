<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Commands;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

class TeamCommandTest extends TestCase
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

    public function testItCanAssignRoleToUserWithTeamId(): void
    {
        $user = User::first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('Role `testRole` assigned to user ID ' . $user->id . ' successfully.', $output);

        setPermissionsTeamId(1);
        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole('testRole'));
    }

    public function testItCanAssignRoleToUserOnDifferentTeams(): void
    {
        $user = User::first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        Artisan::call('permission:assign-role', [
            'name' => 'testRole2',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 2,
        ]);

        setPermissionsTeamId(1);
        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole('testRole'));
        $this->assertFalse($user->hasRole('testRole2'));

        setPermissionsTeamId(2);
        $user->unsetRelation('roles');
        $this->assertTrue($user->hasRole('testRole2'));
        $this->assertFalse($user->hasRole('testRole'));
    }

    public function testItRestoresPreviousTeamIdAfterAssigningRole(): void
    {
        $user = User::first();

        setPermissionsTeamId(5);

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        $this->assertSame(5, getPermissionsTeamId());
    }

    public function testItPreservesZeroAsAnExplicitTeamId(): void
    {
        $user = User::first();
        setPermissionsTeamId(5);

        Artisan::call('permission:create-role', [
            'name' => 'zero-team-role',
            '--team-id' => '0',
        ]);

        $this->assertSame(5, getPermissionsTeamId());
        $this->assertDatabaseHas('roles', [
            'name' => 'zero-team-role',
            'team_test_id' => 0,
        ]);

        Artisan::call('permission:assign-role', [
            'name' => 'zero-team-role',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => '0',
        ]);

        $this->assertSame(5, getPermissionsTeamId());

        setPermissionsTeamId('0');
        $user->unsetRelation('roles');

        $this->assertTrue($user->hasRole('zero-team-role'));
    }
}
