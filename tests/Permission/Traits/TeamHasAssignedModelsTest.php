<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class TeamHasAssignedModelsTest extends HasAssignedModelsTest
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

    public function testItAssignsModelsInCurrentTeamWhenModelAlreadyHasRoleInAnotherTeam(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        setPermissionsTeamId(2);
        $user->assignRole($this->testUserRole);

        setPermissionsTeamId(1);
        $this->testUserRole->assignToModels($user);

        $this->assertSame(2, $this->roleAssignmentsFor($user));

        setPermissionsTeamId(1);
        $this->assertTrue($user->fresh()->hasRole($this->testUserRole));

        setPermissionsTeamId(2);
        $this->assertTrue($user->fresh()->hasRole($this->testUserRole));
    }

    public function testItRemovesModelsOnlyFromCurrentTeam(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        setPermissionsTeamId(1);
        $user->assignRole($this->testUserRole);

        setPermissionsTeamId(2);
        $user->assignRole($this->testUserRole);

        setPermissionsTeamId(1);
        $this->testUserRole->removeFromModels($user);

        $this->assertSame(1, $this->roleAssignmentsFor($user));
        $this->assertFalse($user->fresh()->hasRole($this->testUserRole));

        setPermissionsTeamId(2);
        $this->assertTrue($user->fresh()->hasRole($this->testUserRole));
    }

    public function testItSyncsModelsOnlyInCurrentTeam(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user3 = User::create(['email' => 'user3@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole($this->testUserRole);

        setPermissionsTeamId(2);
        $user3->assignRole($this->testUserRole);

        setPermissionsTeamId(1);
        $this->testUserRole->syncModels([$user2]);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));

        setPermissionsTeamId(2);
        $this->assertTrue($user3->fresh()->hasRole($this->testUserRole));
    }

    public function testItClearsModelsOnlyInCurrentTeamWhenSyncingEmptyList(): void
    {
        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);

        setPermissionsTeamId(1);
        $user1->assignRole($this->testUserRole);

        setPermissionsTeamId(2);
        $user2->assignRole($this->testUserRole);

        setPermissionsTeamId(1);
        $this->testUserRole->syncModels([]);

        $this->assertFalse($user1->fresh()->hasRole($this->testUserRole));

        setPermissionsTeamId(2);
        $this->assertTrue($user2->fresh()->hasRole($this->testUserRole));
    }

    public function testRawIdsWithExplicitModelClassHonorCurrentTeam(): void
    {
        $user = User::create(['email' => 'user1@test.com']);

        setPermissionsTeamId(2);
        $this->testUserRole->assignToModels($user->getKey(), User::class);

        setPermissionsTeamId(1);
        $this->testUserRole->assignToModels($user->getKey(), User::class);
        $this->testUserRole->removeFromModels($user->getKey(), User::class);

        $this->assertSame(1, $this->roleAssignmentsFor($user));
        $this->assertFalse($user->fresh()->hasRole($this->testUserRole));

        setPermissionsTeamId(2);
        $this->assertTrue($user->fresh()->hasRole($this->testUserRole));
    }

    private function roleAssignmentsFor(User $user): int
    {
        return DB::table(Config::modelHasRolesTable())
            ->where(app(PermissionRegistrar::class)->pivotRole, $this->testUserRole->getKey())
            ->where(Config::morphKey(), $user->getKey())
            ->count();
    }
}
