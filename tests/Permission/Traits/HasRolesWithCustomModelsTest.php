<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Carbon\CarbonImmutable;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\Role;

class HasRolesWithCustomModelsTest extends HasRolesTest
{
    protected int $resetDatabaseQuery = 0;

    protected function setUpInCoroutine(): void
    {
        $this->setUpCustomModels();
    }

    public function testItCanUseCustomModelRole(): void
    {
        $this->assertSame(Role::class, $this->testUserRole::class);
    }

    public function testFindOrCreateRestoresSoftDeletedRole(): void
    {
        $role = Role::create(['name' => 'restorable-role']);
        $roleId = $role->getKey();
        $role->givePermissionTo($this->testUserPermission);
        $this->testUser->assignRole($role);

        $role->delete();

        $restoredRole = Role::findOrCreate('restorable-role');

        $this->assertSame($roleId, $restoredRole->getKey());
        $this->assertFalse($restoredRole->trashed());
        $this->assertNull($restoredRole->deleted_at);
        $this->assertSame(1, Role::withTrashed()->where('name', 'restorable-role')->count());
        $this->assertTrue($restoredRole->hasPermissionTo($this->testUserPermission));
        $this->assertTrue($this->testUser->fresh()->hasRole($restoredRole));
    }

    public function testItDoesNotDetachPermissionsWhenSoftDeleting(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        DB::enableQueryLog();
        $this->testUserRole->delete();
        DB::disableQueryLog();

        $this->assertCount(1 + $this->resetDatabaseQuery, DB::getQueryLog());

        $role = Role::onlyTrashed()->find($this->testUserRole->getKey());

        $this->assertSame(
            1,
            DB::table(config('permission.table_names.role_has_permissions'))
                ->where('role_test_id', $role->getKey())
                ->count(),
        );
    }

    public function testItDoesNotDetachUsersWhenSoftDeleting(): void
    {
        $this->testUser->assignRole($this->testUserRole);

        DB::enableQueryLog();
        $this->testUserRole->delete();
        DB::disableQueryLog();

        $this->assertCount(1 + $this->resetDatabaseQuery, DB::getQueryLog());

        $role = Role::onlyTrashed()->find($this->testUserRole->getKey());

        $this->assertSame(
            1,
            DB::table(config('permission.table_names.model_has_roles'))
                ->where('role_test_id', $role->getKey())
                ->count(),
        );
    }

    public function testItDoesDetachPermissionsAndUsersWhenForceDeleting(): void
    {
        $roleId = $this->testUserRole->getKey();
        $this->testUserPermission->assignRole($roleId);
        $this->testUser->assignRole($roleId);

        DB::enableQueryLog();
        $this->testUserRole->forceDelete();
        DB::disableQueryLog();

        $this->assertCount(3 + $this->resetDatabaseQuery, DB::getQueryLog());

        $this->assertNull(Role::withTrashed()->find($roleId));
        $this->assertSame(
            0,
            DB::table(config('permission.table_names.role_has_permissions'))
                ->where('role_test_id', $roleId)
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table(config('permission.table_names.model_has_roles'))
                ->where('role_test_id', $roleId)
                ->count(),
        );
    }

    public function testItTouchesWhenAssigningNewRoles(): void
    {
        CarbonImmutable::setTestNow('2021-07-19 10:13:14');

        $user = Admin::create(['email' => 'user1@test.com']);
        $role1 = Role::create(['name' => 'testRoleInWebGuard', 'guard_name' => 'admin']);
        $role2 = Role::create(['name' => 'testRoleInWebGuard1', 'guard_name' => 'admin']);

        $this->assertSame('2021-07-19 10:13:14', $role1->updated_at->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow('2021-07-20 19:13:14');

        $user->syncRoles([$role1->getKey(), $role2->getKey()]);

        $this->assertSame('2021-07-20 19:13:14', $role1->refresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2021-07-20 19:13:14', $role2->refresh()->updated_at->format('Y-m-d H:i:s'));
    }
}
