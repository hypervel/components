<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Concerns\HasUuids;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionWorkspaceTeam;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

class PartitionCustomPivotTest extends PartitionTestCase
{
    protected bool $partitionTeams = true;

    protected function setUp(): void
    {
        parent::setUp();

        PartitionCustomPermissionPivot::$events = [];
        PartitionCustomRolePivot::$events = [];
    }

    public function testCustomPermissionWritesRetainTheCapturedPartitionAndTeam(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $user = PartitionCustomPivotUser::create(['email' => 'custom@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);

        setPermissionsTeamId($teamA1);
        $user->givePermissionTo($permissionA);

        setPermissionsTeamId($teamA2);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        setPermissionsTeamId($teamB);
        $user->givePermissionTo($permissionB);

        $this->setPartition(self::PARTITION_A);
        setPermissionsTeamId($teamA1);
        PartitionCustomPermissionPivot::$events = [];

        $user->denyPermissionTo($permissionA);

        $this->assertSame(['updated'], PartitionCustomPermissionPivot::$events);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA1->getKey(),
            'permission_test_id' => $permissionA->getKey(),
            'is_denied' => true,
        ]);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA2->getKey(),
            'permission_test_id' => $permissionA->getKey(),
            'is_denied' => false,
        ]);

        PartitionCustomPermissionPivot::$events = [];
        $user->revokePermissionTo($permissionA);

        $this->assertSame(['deleted'], PartitionCustomPermissionPivot::$events);
        $this->assertDatabaseMissing(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA1->getKey(),
            'permission_test_id' => $permissionA->getKey(),
        ]);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA2->getKey(),
            'permission_test_id' => $permissionA->getKey(),
        ]);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_B,
            'team_test_id' => $teamB->getKey(),
            'permission_test_id' => $permissionB->getKey(),
        ]);
    }

    public function testDeferredCustomPivotAssignmentsRetainTheirCapturedContexts(): void
    {
        $teamA = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user = new PartitionCustomPivotUser(['email' => 'deferred@example.com']);

        setPermissionsTeamId($teamA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        setPermissionsTeamId($teamB);
        $user->denyPermissionTo($permissionB);

        PartitionContext::forget();
        setPermissionsTeamId(null);
        $user->save();

        $this->assertSame(['created', 'created'], PartitionCustomPermissionPivot::$events);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'team_test_id' => $teamA->getKey(),
            'permission_test_id' => $permissionA->getKey(),
            'is_denied' => false,
        ]);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_B,
            'team_test_id' => $teamB->getKey(),
            'permission_test_id' => $permissionB->getKey(),
            'is_denied' => true,
        ]);
    }

    public function testCustomRoleWritesRetainTheCapturedPartitionAndTeam(): void
    {
        $teamA1 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A One']);
        $teamA2 = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_A, 'name' => 'A Two']);
        $user = PartitionCustomPivotUser::create(['email' => 'roles@example.com']);

        setPermissionsTeamId($teamA1);
        $roleA1 = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA1);

        setPermissionsTeamId($teamA2);
        $roleA2 = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA2);

        $this->setPartition(self::PARTITION_B);
        $teamB = PartitionWorkspaceTeam::create(['workspace_id' => self::PARTITION_B, 'name' => 'B One']);
        setPermissionsTeamId($teamB);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleB);

        $this->setPartition(self::PARTITION_A);
        setPermissionsTeamId($teamA1);
        PartitionCustomRolePivot::$events = [];

        $user->removeRole($roleA1);

        $this->assertSame(['deleted'], PartitionCustomRolePivot::$events);
        $this->assertDatabaseMissing(Config::modelHasRolesTable(), [
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
}

class PartitionCustomPivotUser extends UserWithoutHasRoles
{
    use HasRoles {
        permissions as protected traitPermissions;
        roles as protected traitRoles;
    }
    use HasUuids;

    protected ?string $table = 'global_partition_users';

    protected string $guard_name = 'web';

    /**
     * @return BelongsToMany<Permission, $this, PartitionCustomPermissionPivot>
     */
    public function permissions(): BelongsToMany
    {
        return $this->traitPermissions()->using(PartitionCustomPermissionPivot::class);
    }

    /**
     * @return BelongsToMany<Role, $this, PartitionCustomRolePivot>
     */
    public function roles(): BelongsToMany
    {
        return $this->traitRoles()->using(PartitionCustomRolePivot::class);
    }
}

class PartitionCustomPermissionPivot extends MorphPivot
{
    protected array $casts = [
        'is_denied' => 'boolean',
    ];

    protected array $guarded = ['is_denied'];

    public static array $events = [];

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (): void {
            static::$events[] = 'created';
        });
        static::updated(function (): void {
            static::$events[] = 'updated';
        });
        static::deleted(function (): void {
            static::$events[] = 'deleted';
        });
    }
}

class PartitionCustomRolePivot extends MorphPivot
{
    public static array $events = [];

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (): void {
            static::$events[] = 'created';
        });
        static::deleted(function (): void {
            static::$events[] = 'deleted';
        });
    }
}
