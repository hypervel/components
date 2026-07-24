<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\HasPermissionsOnlyUser;
use Hypervel\Tests\Permission\Fixtures\Models\Team;

class TeamDeletionTest extends TestCase
{
    /**
     * Enable teams before the Permission migration runs.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.teams', true);
    }

    /**
     * Initialize team-aware Permission state inside the test coroutine.
     */
    protected function setUpInCoroutine(): void
    {
        $this->setUpTeams();
    }

    public function testHardDeletionInvalidatesEveryTeamForAHasPermissionsOnlySubject(): void
    {
        $teamOne = Team::create(['name' => 'One']);
        $teamTwo = Team::create(['name' => 'Two']);
        $user = HasPermissionsOnlyUser::create(['email' => 'permissions-only@example.com']);

        setPermissionsTeamId($teamOne);
        $user->givePermissionTo($this->testUserPermission);
        $readerOne = HasPermissionsOnlyUser::query()->findOrFail($user->getKey());
        $this->assertTrue($readerOne->hasDirectPermission($this->testUserPermission));

        setPermissionsTeamId($teamTwo);
        $user->givePermissionTo($this->testUserPermission);
        $readerTwo = HasPermissionsOnlyUser::query()->findOrFail($user->getKey());
        $this->assertTrue($readerTwo->hasDirectPermission($this->testUserPermission));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $user->delete();

        $discoveryQueries = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'select distinct')
                && str_contains($query['query'], Config::modelHasPermissionsTable()),
        ));

        $this->assertCount(1, $discoveryQueries);
        $this->assertStringContainsString(Config::teamForeignKey(), $discoveryQueries[0]['query']);
        $this->assertStringNotContainsString('workspace_id', $discoveryQueries[0]['query']);

        setPermissionsTeamId($teamOne);
        $this->assertFalse($readerOne->hasDirectPermission($this->testUserPermission));

        setPermissionsTeamId($teamTwo);
        $this->assertFalse($readerTwo->hasDirectPermission($this->testUserPermission));
        $this->assertSame(0, DB::table(Config::modelHasPermissionsTable())->count());
    }
}
