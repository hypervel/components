<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Commands;

use Hypervel\Contracts\Console\Kernel;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Commands\UpgradeForTeamsCommand;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\Attributes\DefineEnvironment;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class CommandTest extends TestCase
{
    /**
     * The teams migrations that existed before the test.
     *
     * @var array<int, string>
     */
    private array $existingTeamsMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        $this->existingTeamsMigrations = $this->teamsMigrations();
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->teamsMigrations(), $this->existingTeamsMigrations) as $migration) {
            unlink($migration);
        }

        parent::tearDown();
    }

    public function testItCanCreateARole(): void
    {
        Artisan::call('permission:create-role', ['name' => 'new-role']);

        $this->assertCount(1, Role::where('name', 'new-role')->get());
        $this->assertCount(0, Role::where('name', 'new-role')->first()->permissions);
    }

    public function testItCanCreateARoleWithASpecificGuard(): void
    {
        Artisan::call('permission:create-role', [
            'name' => 'new-role',
            'guard' => 'api',
        ]);

        $this->assertCount(1, Role::where('name', 'new-role')->where('guard_name', 'api')->get());
    }

    public function testItCanCreateAPermission(): void
    {
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);

        $this->assertCount(1, Permission::where('name', 'new-permission')->get());
    }

    public function testItCanCreateAPermissionWithASpecificGuard(): void
    {
        Artisan::call('permission:create-permission', [
            'name' => 'new-permission',
            'guard' => 'api',
        ]);

        $this->assertCount(1, Permission::where('name', 'new-permission')->where('guard_name', 'api')->get());
    }

    public function testItCanCreateARoleAndPermissionsAtSameTime(): void
    {
        Artisan::call('permission:create-role', [
            'name' => 'new-role',
            'permissions' => 'first permission | second permission',
        ]);

        $role = Role::where('name', 'new-role')->first();

        $this->assertTrue($role->hasPermissionTo('first permission'));
        $this->assertTrue($role->hasPermissionTo('second permission'));
    }

    public function testItCanCreateARoleWithoutDuplication(): void
    {
        Artisan::call('permission:create-role', ['name' => 'new-role']);
        Artisan::call('permission:create-role', ['name' => 'new-role']);

        $this->assertCount(1, Role::where('name', 'new-role')->get());
        $this->assertCount(0, Role::where('name', 'new-role')->first()->permissions);
    }

    public function testItCanCreateAPermissionWithoutDuplication(): void
    {
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);
        Artisan::call('permission:create-permission', ['name' => 'new-permission']);

        $this->assertCount(1, Permission::where('name', 'new-permission')->get());
    }

    public function testItCanShowPermissionTables(): void
    {
        Role::where('name', 'testRole2')->delete();
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

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\|\s+edit-articles\s+\|\s+✔\s+\|\s+·\s+\|/', $output);

        Role::findByName('testRole')->denyPermissionTo('edit-articles');

        Artisan::call('permission:show');

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\|\s+edit-articles\s+\|\s+✘\s+\|\s+·\s+\|/', $output);
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
        config()->set('permission.teams', true);

        $this->artisan('permission:setup-teams')
            ->expectsQuestion('Proceed with the migration creation?', 'yes')
            ->expectsOutputToContain('Migration created successfully.')
            ->assertExitCode(0);

        $matchingFiles = array_values(array_diff($this->teamsMigrations(), $this->existingTeamsMigrations));
        $this->assertCount(1, $matchingFiles);

        $addTeamsFields = require $matchingFiles[0];
        $addTeamsFields->up();
        $addTeamsFields->up(); // test upgrade teams migration fresh

        // Upstream's roles table already has this column, but here it was added after the
        // seeded roles cached their guardable columns.
        Model::flushGuardableColumns();

        Role::create(['name' => 'new-role', 'team_test_id' => 1]);
        $role = Role::where('name', 'new-role')->first();
        $this->assertNotNull($role);
        $this->assertSame(1, (int) $role->team_test_id);
    }

    public function testItFailsToSetupTeamsWhenTheTeamsFeatureIsDisabled(): void
    {
        config()->set('permission.teams', false);

        $this->artisan('permission:setup-teams')
            ->expectsOutputToContain('Teams feature is disabled in your permission.php file.')
            ->assertExitCode(1);
    }

    public function testItCanDeclineTheTeamsMigrationCreation(): void
    {
        config()->set('permission.teams', true);

        $this->artisan('permission:setup-teams')
            ->expectsConfirmation('Proceed with the migration creation?', 'no')
            ->assertExitCode(0);

        $this->assertSame($this->existingTeamsMigrations, $this->teamsMigrations());
    }

    public function testItWarnsWhenATeamsMigrationAlreadyExists(): void
    {
        config()->set('permission.teams', true);

        $existingMigration = database_path('migrations/0000_00_00_000000_add_teams_fields.php');
        file_put_contents($existingMigration, '<?php');
        $migrations = $this->teamsMigrations();

        $this->artisan('permission:setup-teams')
            ->expectsOutputToContain('Setup teams migration already exists.')
            ->expectsConfirmation('Proceed with the migration creation?', 'no')
            ->assertExitCode(0);

        $this->assertSame($migrations, $this->teamsMigrations());
    }

    public function testItWarnsWhenMultipleTeamsMigrationsAlreadyExist(): void
    {
        config()->set('permission.teams', true);

        $existingMigration1 = database_path('migrations/0000_00_00_000000_add_teams_fields.php');
        $existingMigration2 = database_path('migrations/0000_00_00_000001_add_teams_fields.php');
        file_put_contents($existingMigration1, '<?php');
        file_put_contents($existingMigration2, '<?php');
        $migrations = $this->teamsMigrations();

        $this->artisan('permission:setup-teams')
            ->expectsOutputToContain('Setup teams migrations already exist.')
            ->expectsConfirmation('Proceed with the migration creation?', 'no')
            ->assertExitCode(0);

        $this->assertSame($migrations, $this->teamsMigrations());
    }

    public function testItShowsAnErrorWhenTheTeamsMigrationCannotBeCreated(): void
    {
        config()->set('permission.teams', true);

        // CI runs as root, which can still write to a read-only directory, so
        // replace upstream's chmod with a destination that cannot be written.
        $this->app->make(Kernel::class)->registerCommand(new InvalidDestinationUpgradeForTeamsCommand);

        $this->artisan('permission:setup-teams')
            ->expectsConfirmation('Proceed with the migration creation?', 'yes')
            ->expectsOutputToContain("Couldn't create migration.")
            ->assertExitCode(1);

        $this->assertSame($this->existingTeamsMigrations, $this->teamsMigrations());
    }

    #[DefineEnvironment('usesTeams')]
    public function testItCanShowRolesByTeams(): void
    {
        Role::where('name', 'testRole2')->delete();
        Role::create(['name' => 'testRole_2']);
        // Non-sequential team ids, so the headers must show the ids rather than group positions.
        Role::create(['name' => 'testRole_Team', 'team_test_id' => 7]);
        Role::create(['name' => 'testRole_Team', 'team_test_id' => 42]); // same name different team
        Artisan::call('permission:show');

        $output = Artisan::output();

        $this->assertMatchesRegularExpression('/\|\s+\|\s+Team ID: NULL\s+\|\s+Team ID: 7\s+\|\s+Team ID: 42\s+\|/', $output);
        $this->assertMatchesRegularExpression('/\|\s+\|\s+testRole\s+\|\s+testRole_2\s+\|\s+testRole_Team\s+\|\s+testRole_Team\s+\|/', $output);
    }

    public function testItCanRespondToAboutCommandWithDefault(): void
    {
        app(PermissionRegistrar::class)->initializeCache();

        Artisan::call('about');
        $output = str_replace("\r\n", "\n", Artisan::output());

        $pattern = '/Hypervel Permissions[ .\n]*Features Enabled[ .]*Default[ .\n]*Version/';
        $this->assertMatchesRegularExpression($pattern, $output);
    }

    public function testItCanRespondToAboutCommandWithTeams(): void
    {
        app(PermissionRegistrar::class)->initializeCache();

        config()->set('permission.teams', true);

        Artisan::call('about');
        $output = str_replace("\r\n", "\n", Artisan::output());

        $pattern = '/Hypervel Permissions[ .\n]*Features Enabled[ .]*Teams[ .\n]*Version/';
        $this->assertMatchesRegularExpression($pattern, $output);
    }

    public function testItCanAssignRoleToUser(): void
    {
        $user = User::first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => User::class,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('Role `testRole` assigned to user ID ' . $user->id . ' successfully.', $output);
        $this->assertCount(1, Role::where('name', 'testRole')->get());
        $this->assertCount(1, $user->roles);
        $this->assertTrue($user->hasRole('testRole'));
    }

    public function testItFailsToAssignRoleWhenUserNotFound(): void
    {
        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => 99999,
            'guard' => 'web',
            'userModelNamespace' => User::class,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('User with ID 99999 not found.', $output);
    }

    public function testItFailsToAssignRoleWhenNamespaceInvalid(): void
    {
        $user = User::first();

        $userModelClass = 'App\Models\NonExistentUser';

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => $userModelClass,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString("User model class [{$userModelClass}] does not exist.", $output);
    }

    public function testItFailsToAssignRoleWhenModelDoesNotUseHasRoles(): void
    {
        $user = UserWithoutHasRoles::create(['email' => 'plain@user.com']);

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'guard' => 'web',
            'userModelNamespace' => UserWithoutHasRoles::class,
        ]);

        $this->assertStringContainsString('must use the HasRoles trait', Artisan::output());
    }

    #[TestWith([1])]
    #[TestWith(['0'])]
    public function testItWarnsWhenAssigningRoleWithTeamIdButTeamsDisabled(int|string $teamId): void
    {
        $user = User::first();

        Artisan::call('permission:assign-role', [
            'name' => 'testRole',
            'userId' => $user->id,
            'userModelNamespace' => User::class,
            '--team-id' => $teamId,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('Teams feature disabled', $output);
    }

    #[TestWith([1])]
    #[TestWith(['0'])]
    public function testItWarnsWhenCreatingARoleWithTeamIdButTeamsDisabled(int|string $teamId): void
    {
        Artisan::call('permission:create-role', [
            'name' => 'new-role',
            '--team-id' => $teamId,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('Teams feature disabled, argument --team-id has no effect', $output);
        $this->assertCount(0, Role::where('name', 'new-role')->get());
    }

    #[DefineEnvironment('usesTeams')]
    public function testItWarnsWhenCreatingARoleWithTeamIdAndTheRoleAlreadyExistsOnTheGlobalTeam(): void
    {
        Artisan::call('permission:create-role', ['name' => 'new-role']);

        Artisan::call('permission:create-role', [
            'name' => 'new-role',
            '--team-id' => 1,
        ]);

        $output = Artisan::output();

        $this->assertStringContainsString('Role `new-role` already exists on the global team; argument --team-id has no effect', $output);
        $this->assertCount(1, Role::where('name', 'new-role')->get());
    }

    /**
     * Get the teams migrations in the migrations directory.
     *
     * @return array<int, string>
     */
    private function teamsMigrations(): array
    {
        return glob(database_path('migrations/*_add_teams_fields.php')) ?: [];
    }
}

class InvalidDestinationUpgradeForTeamsCommand extends UpgradeForTeamsCommand
{
    /**
     * Return an existing directory as the migration destination.
     */
    protected function getMigrationPath(?string $date = null): string
    {
        return $date === null ? database_path('migrations') : parent::getMigrationPath($date);
    }
}
