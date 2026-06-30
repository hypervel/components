<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
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
}
