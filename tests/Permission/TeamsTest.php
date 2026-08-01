<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Permission\Fixtures\Models\Team;

class TeamsTest extends TestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set([
            'permission.teams' => true,
            'permission.models.team' => Team::class,
        ]);
    }

    public function testTeamEnabledSchemaCreatesTeamColumns(): void
    {
        $this->assertTrue(Schema::hasColumn('roles', 'team_test_id'));
        $this->assertTrue(Schema::hasColumn('model_has_roles', 'team_test_id'));
        $this->assertTrue(Schema::hasColumn('model_has_permissions', 'team_test_id'));
    }

    public function testRoleAssignmentsAreIsolatedByTeamContext(): void
    {
        setPermissionsTeamId(1);
        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasRole('testRole'));

        setPermissionsTeamId(2);

        $this->assertFalse($this->testUser->hasRole('testRole'));

        $this->testUser->assignRole('testRole');

        $this->assertTrue($this->testUser->hasRole('testRole'));
    }

    public function testTeamContextRejectsKeylessModelsWithoutReplacingTheCurrentContext(): void
    {
        setPermissionsTeamId(1);

        try {
            setPermissionsTeamId(new Team(['name' => 'Unsaved']));

            $this->fail('A keyless team model was accepted as the permission team context.');
        } catch (MissingAttributeException $exception) {
            $this->assertStringContainsString('The attribute [id]', $exception->getMessage());
        }

        $this->assertSame(1, $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());
    }

    public function testExplicitNullStillClearsTheTeamContext(): void
    {
        setPermissionsTeamId('0');

        $this->assertSame('0', $this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());

        setPermissionsTeamId(null);

        $this->assertNull($this->app->make(PermissionRegistrar::class)->getPermissionsTeamId());
    }
}
