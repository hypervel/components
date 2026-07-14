<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionPermissionsOnlyUser;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionWorkspaceTeam;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

class PartitionTeamsTest extends PartitionTestCase
{
    protected bool $partitionTeams = true;

    public function testOnlyRoleTeamColumnsAreNullable(): void
    {
        $roleColumns = collect(Schema::getColumns(Config::rolesTable()));
        $roleAssignmentColumns = collect(Schema::getColumns(Config::modelHasRolesTable()));
        $permissionAssignmentColumns = collect(Schema::getColumns(Config::modelHasPermissionsTable()));

        $this->assertTrue($roleColumns->firstWhere('name', 'team_test_id')['nullable']);
        $this->assertFalse($roleAssignmentColumns->firstWhere('name', 'team_test_id')['nullable']);
        $this->assertFalse($permissionAssignmentColumns->firstWhere('name', 'team_test_id')['nullable']);
    }

    public function testPartitionAndTeamRemainIndependentAssignmentDimensions(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);

        setPermissionsTeamId($teamA1);
        $roleA1 = PartitionedRole::create(['name' => 'member']);
        $roleA1->givePermissionTo($permissionA);
        $user->assignRole($roleA1);
        $user->givePermissionTo($permissionA);

        setPermissionsTeamId($teamA2);
        $roleA2 = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA2);

        $this->assertTrue($user->hasRole($roleA2));
        $this->assertFalse($user->hasDirectPermission($permissionA));
        $this->assertFalse($user->hasPermissionTo($permissionA));

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        setPermissionsTeamId($teamB);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $roleB->givePermissionTo($permissionB);
        $user->assignRole($roleB);

        $this->assertTrue($user->hasRole($roleB));
        $this->assertTrue($user->hasPermissionTo($permissionB));

        $this->setPartition(self::PARTITION_A);
        setPermissionsTeamId($teamA1);

        $this->assertTrue($user->hasRole($roleA1));
        $this->assertTrue($user->hasDirectPermission($permissionA));
        $this->assertTrue($user->hasPermissionTo($permissionA));

        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA1->getKey(),
            'role_test_id' => $roleA1->getKey(),
        ]);
        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA2->getKey(),
            'role_test_id' => $roleA2->getKey(),
        ]);
        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_B,
            'team_test_id' => $teamB->getKey(),
            'role_test_id' => $roleB->getKey(),
        ]);
    }

    public function testChangingTeamReloadsLoadedAssignmentRelationsAutomatically(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);

        setPermissionsTeamId($teamA1);
        $roleA1 = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA1);
        $user->givePermissionTo($permission);
        $user->load(['roles', 'permissions']);

        $this->assertSame([$roleA1->getKey()], $user->roles->modelKeys());
        $this->assertSame([$permission->getKey()], $user->permissions->modelKeys());

        setPermissionsTeamId($teamA2);
        $roleA2 = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA2);

        $this->assertTrue($user->hasRole($roleA2));
        $this->assertFalse($user->hasDirectPermission($permission));
        $this->assertFalse($user->relationLoaded('roles'));
        $this->assertFalse($user->relationLoaded('permissions'));
    }

    public function testTeamSwitchDoesNotInvalidatePartitionOnlyCatalogRelations(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        setPermissionsTeamId($teamA1);

        $role = PartitionedRole::create(['name' => 'member']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $role->givePermissionTo($permission);
        $permission->load('roles');

        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($registrar->loadedRelationIsCurrent($permission, 'roles'));

        setPermissionsTeamId($teamA2);

        $this->assertTrue($registrar->loadedRelationIsCurrent($permission, 'roles'));
        $this->assertSame([$role->getKey()], $permission->roles->modelKeys());
    }

    public function testRolePermissionAndTeamScopesApplyBothDimensions(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $userA1 = GlobalPartitionUser::create(['email' => 'a1@example.com']);
        $userA2 = GlobalPartitionUser::create(['email' => 'a2@example.com']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);

        setPermissionsTeamId($teamA1);
        $roleA1 = PartitionedRole::create(['name' => 'member']);
        $userA1->assignRole($roleA1);
        $userA1->givePermissionTo($permission);

        setPermissionsTeamId($teamA2);
        $roleA2 = PartitionedRole::create(['name' => 'member']);
        $userA2->assignRole($roleA2);
        $userA2->givePermissionTo($permission);

        $this->assertSame([$userA2->getKey()], GlobalPartitionUser::role('member')->pluck('id')->all());
        $this->assertSame([$userA2->getKey()], GlobalPartitionUser::permission('articles.edit')->pluck('id')->all());
        $this->assertSame([$userA2->getKey()], GlobalPartitionUser::team($teamA2)->pluck('id')->all());
        $this->assertSame([$userA1->getKey()], GlobalPartitionUser::withoutTeam($teamA2)->pluck('id')->all());

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        setPermissionsTeamId($teamB);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $userA1->assignRole($roleB);

        $this->assertSame([$userA1->getKey()], GlobalPartitionUser::role('member')->pluck('id')->all());
        $this->assertSame([$userA2->getKey()], GlobalPartitionUser::withoutRole('member')->pluck('id')->all());
        $this->assertSame([], GlobalPartitionUser::team($teamA2)->pluck('id')->all());
    }

    public function testTeamsRelationReadsOnlyCurrentPartitionAssignments(): void
    {
        $teamA = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        setPermissionsTeamId($teamA);
        $user->assignRole(PartitionedRole::create(['name' => 'member']));

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        setPermissionsTeamId($teamB);
        $user->assignRole(PartitionedRole::create(['name' => 'member']));

        $this->assertSame([$teamB->getKey()], $user->teams()->pluck('partition_workspace_teams.id')->all());

        $this->setPartition(self::PARTITION_A);
        setPermissionsTeamId($teamA);

        $this->assertSame([$teamA->getKey()], $user->teams()->pluck('partition_workspace_teams.id')->all());
    }

    public function testQueuedSynchronizationReplacesOnlyTheCapturedPartitionAndTeam(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $firstRole = PartitionedRole::create(['name' => 'first']);
        $secondRole = PartitionedRole::create(['name' => 'second']);
        $firstPermission = PartitionedPermission::create(['name' => 'first']);
        $secondPermission = PartitionedPermission::create(['name' => 'second']);
        $user = new GlobalPartitionUser(['email' => 'queued@example.com']);

        setPermissionsTeamId($teamA1);
        $user->assignRole($firstRole);
        $user->givePermissionTo($firstPermission);

        setPermissionsTeamId($teamA2);
        $user->assignRole($firstRole);
        $user->giveForbiddenTo($firstPermission);

        setPermissionsTeamId($teamA1);
        $user->syncRoles($secondRole);
        $user->syncPermissions($secondPermission);

        PartitionContext::forget();
        setPermissionsTeamId(null);
        $user->save();

        $this->setPartition(self::PARTITION_A);
        setPermissionsTeamId($teamA1);
        $this->assertFalse($user->hasRole($firstRole));
        $this->assertTrue($user->hasRole($secondRole));
        $this->assertFalse($user->hasDirectPermission($firstPermission));
        $this->assertTrue($user->hasDirectPermission($secondPermission));

        setPermissionsTeamId($teamA2);
        $this->assertTrue($user->hasRole($firstRole));
        $this->assertTrue($user->hasForbiddenPermission($firstPermission));
        $this->assertFalse($user->hasRole($secondRole));
        $this->assertFalse($user->hasDirectPermission($secondPermission));
    }

    public function testHardDeletionInvalidatesEveryPartitionAndTeamForAHasPermissionsOnlySubject(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $user = GlobalPartitionPermissionsOnlyUser::create(['email' => 'global@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);

        setPermissionsTeamId($teamA1);
        $user->givePermissionTo($permissionA);
        $readerA1 = GlobalPartitionPermissionsOnlyUser::query()->findOrFail($user->getKey());
        $this->assertTrue($readerA1->hasDirectPermission($permissionA));

        setPermissionsTeamId($teamA2);
        $user->givePermissionTo($permissionA);
        $readerA2 = GlobalPartitionPermissionsOnlyUser::query()->findOrFail($user->getKey());
        $this->assertTrue($readerA2->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        setPermissionsTeamId($teamB);
        $user->givePermissionTo($permissionB);
        $readerB = GlobalPartitionPermissionsOnlyUser::query()->findOrFail($user->getKey());
        $this->assertTrue($readerB->hasDirectPermission($permissionB));

        DB::flushQueryLog();
        DB::enableQueryLog();

        $user->delete();

        $discoveryQueries = array_values(array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'select distinct')
                && str_contains($query['query'], 'model_has_permissions'),
        ));

        $this->assertCount(1, $discoveryQueries);
        $this->assertStringContainsString('workspace_id', $discoveryQueries[0]['query']);
        $this->assertStringContainsString('team_test_id', $discoveryQueries[0]['query']);

        $this->setPartition(self::PARTITION_A);
        setPermissionsTeamId($teamA1);
        $this->assertFalse($readerA1->hasDirectPermission($permissionA));

        setPermissionsTeamId($teamA2);
        $this->assertFalse($readerA2->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);
        setPermissionsTeamId($teamB);
        $this->assertFalse($readerB->hasDirectPermission($permissionB));
        $this->assertSame(0, DB::table(Config::modelHasPermissionsTable())->count());
    }
}
