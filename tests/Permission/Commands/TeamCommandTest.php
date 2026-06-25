<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Commands;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Permission\Fixtures\Models\Team;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;

class TeamCommandTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'permission.teams' => true,
            'permission.models.team' => Team::class,
        ]);
    }

    public function testItCanAssignRoleToUserWithTeamId(): void
    {
        $user = User::query()->first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        $this->assertStringContainsString("Role `testRole` assigned to user ID {$user->id} successfully.", Artisan::output());

        setPermissionsTeamId(1);
        $this->assertTrue($user->fresh()->hasRole('testRole'));
    }

    public function testItCanAssignRolesToUserOnDifferentTeams(): void
    {
        $user = User::query()->first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        Artisan::call('permission:assign-role', [
            'name' => 'testRole2',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 2,
        ]);

        setPermissionsTeamId(1);
        $user = $user->fresh();
        $this->assertTrue($user->hasRole('testRole'));
        $this->assertFalse($user->hasRole('testRole2'));

        setPermissionsTeamId(2);
        $user = $user->fresh();
        $this->assertTrue($user->hasRole('testRole2'));
        $this->assertFalse($user->hasRole('testRole'));
    }

    public function testItRestoresPreviousTeamIdAfterAssigningRole(): void
    {
        $user = User::query()->first();

        setPermissionsTeamId(5);

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        $this->assertSame(5, getPermissionsTeamId());
    }

    public function testItCanCreateTeamsMigration(): void
    {
        $before = glob(database_path('migrations/*_add_teams_fields.php')) ?: [];

        try {
            Artisan::call('permission:setup-teams', [
                '--no-interaction' => true,
            ]);

            $after = glob(database_path('migrations/*_add_teams_fields.php')) ?: [];

            $this->assertCount(count($before) + 1, $after);
            $this->assertStringContainsString('Migration created successfully.', Artisan::output());
        } finally {
            foreach (array_diff(glob(database_path('migrations/*_add_teams_fields.php')) ?: [], $before) as $path) {
                unlink($path);
            }
        }
    }

    public function testItCanShowRolesByTeams(): void
    {
        $this->app->make(PermissionRegistrar::class)->initializeCache();

        Role::query()->where('name', 'testRole2')->delete();
        Role::create(['name' => 'testRole_2']);
        Role::create(['name' => 'testRole_Team', 'team_test_id' => 1]);
        Role::create(['name' => 'testRole_Team', 'team_test_id' => 2]);

        Artisan::call('permission:show');

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\|\s+\|\s+Team ID: NULL\s+\|\s+Team ID: 1\s+\|\s+Team ID: 2\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s+\|\s+testRole\s+\|\s+testRole_2\s+\|\s+testRole_Team\s+\|\s+testRole_Team\s+\|/', $output);
    }
}
