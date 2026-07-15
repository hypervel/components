<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use BadMethodCallException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Traits\HasPermissions;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\HasPermissionsOnlyUser;
use UnitEnum;

class DeletionTest extends TestCase
{
    public function testHardDeletingAHasPermissionsOnlySubjectDeletesItsDirectAssignments(): void
    {
        $user = HasPermissionsOnlyUser::create(['email' => 'permissions-only@example.com']);
        $user->givePermissionTo($this->testUserPermission);

        $this->assertSame(1, $this->directPermissionAssignmentCount($user));

        $user->delete();

        $this->assertSame(0, $this->directPermissionAssignmentCount($user));
    }

    public function testHardDeletingAnUnpartitionedSubjectWithoutTeamsNeedsNoDiscoveryQuery(): void
    {
        $user = HasPermissionsOnlyUser::create(['email' => 'no-discovery@example.com']);
        $user->givePermissionTo($this->testUserPermission);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $user->delete();

        $discoveryQueries = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'select distinct')
                && str_contains($query['query'], Config::modelHasPermissionsTable()),
        );

        $this->assertCount(0, $discoveryQueries);
        $this->assertSame(0, $this->directPermissionAssignmentCount($user));
    }

    public function testHardDeletingAHasRolesSubjectDeletesBothAssignmentKinds(): void
    {
        $this->testUser->assignRole($this->testUserRole);
        $this->testUser->givePermissionTo($this->testUserPermission);

        $this->assertSame(1, $this->roleAssignmentCount($this->testUser));
        $this->assertSame(1, $this->directPermissionAssignmentCount($this->testUser));

        $this->testUser->delete();

        $this->assertSame(0, $this->roleAssignmentCount($this->testUser));
        $this->assertSame(0, $this->directPermissionAssignmentCount($this->testUser));
    }

    public function testCustomRoleCleanupDoesNotDependOnRefreshesPermissionCache(): void
    {
        $role = StandaloneRole::query()->create([
            'name' => 'standalone-role',
            'guard_name' => 'web',
        ]);

        DB::table(Config::modelHasRolesTable())->insert([
            app('config')->get('permission.column_names.role_pivot_key') => $role->getKey(),
            Config::morphKey() => $this->testUser->getKey(),
            'model_type' => $this->testUser->getMorphClass(),
        ]);
        DB::table(Config::roleHasPermissionsTable())->insert([
            app('config')->get('permission.column_names.role_pivot_key') => $role->getKey(),
            app('config')->get('permission.column_names.permission_pivot_key') => $this->testUserPermission->getKey(),
            'is_denied' => false,
        ]);

        $cleanupObservedBeforeRecordDeletion = false;

        StandaloneRole::deleting(function () use ($role, &$cleanupObservedBeforeRecordDeletion): void {
            $this->assertSame(0, DB::table(Config::modelHasRolesTable())
                ->where(app('config')->get('permission.column_names.role_pivot_key'), $role->getKey())
                ->count());
            $this->assertSame(0, DB::table(Config::roleHasPermissionsTable())
                ->where(app('config')->get('permission.column_names.role_pivot_key'), $role->getKey())
                ->count());

            $cleanupObservedBeforeRecordDeletion = true;
        });

        $role->delete();

        $this->assertTrue($cleanupObservedBeforeRecordDeletion);
    }

    public function testCustomPermissionCleanupDoesNotDependOnRefreshesPermissionCache(): void
    {
        $permission = StandalonePermission::query()->create([
            'name' => 'standalone-permission',
            'guard_name' => 'web',
        ]);

        DB::table(Config::modelHasPermissionsTable())->insert([
            app('config')->get('permission.column_names.permission_pivot_key') => $permission->getKey(),
            Config::morphKey() => $this->testUser->getKey(),
            'model_type' => $this->testUser->getMorphClass(),
            'is_denied' => false,
        ]);
        DB::table(Config::roleHasPermissionsTable())->insert([
            app('config')->get('permission.column_names.role_pivot_key') => $this->testUserRole->getKey(),
            app('config')->get('permission.column_names.permission_pivot_key') => $permission->getKey(),
            'is_denied' => false,
        ]);

        $cleanupObservedBeforeRecordDeletion = false;

        StandalonePermission::deleting(function () use ($permission, &$cleanupObservedBeforeRecordDeletion): void {
            $this->assertSame(0, DB::table(Config::modelHasPermissionsTable())
                ->where(app('config')->get('permission.column_names.permission_pivot_key'), $permission->getKey())
                ->count());
            $this->assertSame(0, DB::table(Config::roleHasPermissionsTable())
                ->where(app('config')->get('permission.column_names.permission_pivot_key'), $permission->getKey())
                ->count());

            $cleanupObservedBeforeRecordDeletion = true;
        });

        $permission->delete();

        $this->assertTrue($cleanupObservedBeforeRecordDeletion);
    }

    private function roleAssignmentCount(Model $model): int
    {
        return DB::table(Config::modelHasRolesTable())
            ->where(Config::morphKey(), $model->getKey())
            ->where('model_type', $model->getMorphClass())
            ->count();
    }

    private function directPermissionAssignmentCount(Model $model): int
    {
        return DB::table(Config::modelHasPermissionsTable())
            ->where(Config::morphKey(), $model->getKey())
            ->where('model_type', $model->getMorphClass())
            ->count();
    }
}

class StandaloneRole extends Model implements RoleContract
{
    use HasPermissions;

    protected array $guarded = [];

    protected ?string $table = 'roles';

    public static function findByName(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findById(int|string $id, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findOrCreate(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }
}

class StandalonePermission extends Model implements PermissionContract
{
    use HasRoles;

    protected array $guarded = [];

    protected ?string $table = 'permissions';

    public static function findByName(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findById(int|string $id, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findOrCreate(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }
}
