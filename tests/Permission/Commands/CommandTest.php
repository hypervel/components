<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Commands;

use Composer\InstalledVersions;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Console\AboutCommand;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;

class CommandTest extends TestCase
{
    public function testItCanCreateARole(): void
    {
        Artisan::call('permission:create-role', ['name' => 'new-role']);

        $role = Role::query()->where('name', 'new-role')->first();

        $this->assertNotNull($role);
        $this->assertCount(0, $role->permissions);
    }

    public function testItCanCreateARoleWithASpecificGuard(): void
    {
        Artisan::call('permission:create-role', [
            'name' => 'new-role',
            'guard' => 'api',
        ]);

        $this->assertTrue(Role::query()->where('name', 'new-role')->where('guard_name', 'api')->exists());
    }

    public function testItCanCreateAPermission(): void
    {
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);

        $this->assertTrue(Permission::query()->where('name', 'new-permission')->exists());
    }

    public function testItCanCreateAPermissionWithASpecificGuard(): void
    {
        Artisan::call('permission:create-permission', [
            'name' => 'new-permission',
            'guard' => 'api',
        ]);

        $this->assertTrue(Permission::query()->where('name', 'new-permission')->where('guard_name', 'api')->exists());
    }

    public function testItCanCreateARoleAndPermissionsAtTheSameTime(): void
    {
        Artisan::call('permission:create-role', [
            'name' => 'new-role',
            'permissions' => 'first permission | second permission',
        ]);

        $role = Role::query()->where('name', 'new-role')->first();

        $this->assertTrue($role->hasPermissionTo('first permission'));
        $this->assertTrue($role->hasPermissionTo('second permission'));
    }

    public function testItCanCreateARoleWithoutDuplication(): void
    {
        Artisan::call('permission:create-role', ['name' => 'new-role']);
        Artisan::call('permission:create-role', ['name' => 'new-role']);

        $this->assertCount(1, Role::query()->where('name', 'new-role')->get());
    }

    public function testItCanCreateAPermissionWithoutDuplication(): void
    {
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);

        $this->assertCount(1, Permission::query()->where('name', 'new-permission')->get());
    }

    public function testItCanShowPermissionTables(): void
    {
        Role::query()->where('name', 'testRole2')->delete();
        Role::create(['name' => 'testRole_2']);

        Artisan::call('permission:show');
        $output = Artisan::output();

        $this->assertStringContainsString('Guard: web', $output);
        $this->assertStringContainsString('Guard: admin', $output);
        $this->assertMatchesRegularExpression('/\|\s+\|\s+testRole\s+\|\s+testRole_2\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s+edit-articles\s+\|\s+·\s+\|\s+·\s+\|/', $output);

        Role::findByName('testRole')->givePermissionTo('edit-articles');
        $this->reloadPermissions();

        Artisan::call('permission:show');

        $this->assertMatchesRegularExpression('/\|\s+edit-articles\s+\|\s+✔\s+\|\s+·\s+\|/', Artisan::output());
    }

    public function testItCanShowPermissionsForGuard(): void
    {
        Artisan::call('permission:show', ['guard' => 'web']);
        $output = Artisan::output();

        $this->assertStringContainsString('Guard: web', $output);
        $this->assertStringNotContainsString('Guard: admin', $output);
    }

    public function testItCanShowPermissionsForGuardNamedZero(): void
    {
        Permission::create(['name' => 'zero-permission', 'guard_name' => '0']);

        Artisan::call('permission:show', ['guard' => '0']);
        $output = Artisan::output();

        $this->assertStringContainsString('Guard: 0', $output);
        $this->assertStringNotContainsString('Guard: web', $output);
    }

    public function testItCanSetupTeamsUpgrade(): void
    {
        $this->app->make('config')->set('permission.teams', true);
        $this->app->make(PermissionRegistrar::class)->initializeCache();
        $before = glob(database_path('migrations/*_add_teams_fields.php')) ?: [];

        try {
            Artisan::call('permission:setup-teams', [
                '--no-interaction' => true,
            ]);

            $matchingFiles = array_values(array_diff(glob(database_path('migrations/*_add_teams_fields.php')) ?: [], $before));

            $this->assertNotEmpty($matchingFiles);

            $migration = require $matchingFiles[count($matchingFiles) - 1];
            $migration->up();
            $migration->up();
            Model::flushGuardableColumns();

            Role::create(['name' => 'new-role', 'team_test_id' => 1]);
            $role = Role::query()->where('name', 'new-role')->first();

            $this->assertNotNull($role);
            $this->assertSame(1, (int) $role->team_test_id);
        } finally {
            foreach (array_diff(glob(database_path('migrations/*_add_teams_fields.php')) ?: [], $before) as $path) {
                unlink($path);
            }
        }
    }

    public function testItCanRespondToAboutCommandWithDefaultFeatures(): void
    {
        if (! class_exists(InstalledVersions::class) || ! method_exists(AboutCommand::class, 'flushState')) {
            $this->markTestSkipped('About command package metadata is unavailable in this environment.');
        }

        $this->app->make(PermissionRegistrar::class)->initializeCache();

        Artisan::call('about');
        $output = str_replace("\r\n", "\n", Artisan::output());

        $this->assertMatchesRegularExpression('/Hypervel Permissions[ .\n]*Features Enabled[ .]*Denied Permissions[ .\n]*Version/', $output);
    }

    public function testItCanRespondToAboutCommandWithTeams(): void
    {
        if (! class_exists(InstalledVersions::class) || ! method_exists(AboutCommand::class, 'flushState')) {
            $this->markTestSkipped('About command package metadata is unavailable in this environment.');
        }

        $this->app->make('config')->set('permission.teams', true);
        $this->app->make(PermissionRegistrar::class)->initializeCache();

        Artisan::call('about');
        $output = str_replace("\r\n", "\n", Artisan::output());

        $this->assertMatchesRegularExpression('/Hypervel Permissions[ .\n]*Features Enabled[ .]*Teams, Denied Permissions[ .\n]*Version/', $output);
    }

    public function testItCanAssignRoleToUser(): void
    {
        $user = User::query()->first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
        ]);

        $this->assertStringContainsString("Role `testRole` assigned to user ID {$user->id} successfully.", Artisan::output());
        $this->assertTrue($user->fresh()->hasRole('testRole'));
    }

    public function testItFailsToAssignRoleWhenUserDoesNotExist(): void
    {
        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => '99999',
            'guard' => 'web',
            'userModelNamespace' => User::class,
        ]);

        $this->assertStringContainsString('User with ID 99999 not found.', Artisan::output());
    }

    public function testItFailsToAssignRoleWhenNamespaceInvalid(): void
    {
        $user = User::query()->first();
        $userModelClass = 'App\Models\NonExistentUser';

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => $userModelClass,
        ]);

        $this->assertStringContainsString("User model class [{$userModelClass}] does not exist.", Artisan::output());
    }

    public function testItFailsToAssignRoleWhenModelDoesNotUseHasRoles(): void
    {
        $user = UserWithoutHasRoles::create(['email' => 'plain@user.com']);

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'guard' => 'web',
            'userModelNamespace' => UserWithoutHasRoles::class,
        ]);

        $this->assertStringContainsString('must use the HasRoles trait', Artisan::output());
    }

    public function testItWarnsWhenAssigningRoleWithTeamIdButTeamsDisabled(): void
    {
        $user = User::query()->first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => (string) $user->id,
            'userModelNamespace' => User::class,
            '--team-id' => 1,
        ]);

        $this->assertStringContainsString('Teams feature disabled', Artisan::output());
    }
}
