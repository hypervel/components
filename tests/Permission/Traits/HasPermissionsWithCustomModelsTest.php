<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Traits;

use Carbon\CarbonImmutable;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\Admin;
use Hypervel\Tests\Permission\Fixtures\Models\Permission;
use Hypervel\Tests\Permission\Fixtures\Models\User;

class HasPermissionsWithCustomModelsTest extends HasPermissionsTest
{
    protected int $resetDatabaseQuery = 0;

    protected function setUpInCoroutine(): void
    {
        $this->setUpCustomModels();
    }

    public function testItCanScopeUsersUsingAnInt(): void
    {
        // Skipped because custom model uses uuid, replacement "testItCanScopeUsersUsingAUuid".
        $this->assertTrue(true);
    }

    public function testItCanUseCustomModelPermission(): void
    {
        $this->assertSame(Permission::class, $this->testUserPermission::class);
    }

    public function testItCanUseCustomFieldsFromCache(): void
    {
        DB::connection()->getSchemaBuilder()->table(config('permission.table_names.roles'), function ($table): void {
            $table->string('type')->default('R');
        });
        DB::connection()->getSchemaBuilder()->table(config('permission.table_names.permissions'), function ($table): void {
            $table->string('type')->default('P');
        });

        $this->testUserRole->givePermissionTo($this->testUserPermission);
        app(PermissionRegistrar::class)->getPermissions();

        DB::enableQueryLog();
        $this->assertSame('P', Permission::findByName('edit-articles')->type);
        $this->assertSame('R', Permission::findByName('edit-articles')->roles[0]->type);
        DB::disableQueryLog();

        $this->assertCount(0, DB::getQueryLog());
    }

    public function testItCanScopeUsersUsingAUuid(): void
    {
        $uuid1 = $this->testUserPermission->getKey();
        $uuid2 = app(Permission::class)::where('name', 'edit-news')->first()->getKey();

        $user1 = User::create(['email' => 'user1@test.com']);
        $user2 = User::create(['email' => 'user2@test.com']);
        $user1->givePermissionTo([$uuid1, $uuid2]);
        $this->testUserRole->givePermissionTo($uuid1);
        $user2->assignRole('testRole');

        $this->assertCount(2, User::permission($uuid1)->get());
        $this->assertCount(1, User::permission([$uuid2])->get());
    }

    public function testItDoesNotDetachRolesWhenSoftDeleting(): void
    {
        $this->testUserRole->givePermissionTo($this->testUserPermission);

        DB::enableQueryLog();
        $this->testUserPermission->delete();
        DB::disableQueryLog();

        $this->assertCount(1 + $this->resetDatabaseQuery, DB::getQueryLog());

        $permission = Permission::onlyTrashed()->find($this->testUserPermission->getKey());

        $this->assertSame(
            1,
            DB::table(config('permission.table_names.role_has_permissions'))
                ->where('permission_test_id', $permission->getKey())
                ->count(),
        );
    }

    public function testItDoesNotDetachUsersWhenSoftDeleting(): void
    {
        $this->testUser->givePermissionTo($this->testUserPermission);

        DB::enableQueryLog();
        $this->testUserPermission->delete();
        DB::disableQueryLog();

        $this->assertCount(1 + $this->resetDatabaseQuery, DB::getQueryLog());

        $permission = Permission::onlyTrashed()->find($this->testUserPermission->getKey());

        $this->assertSame(
            1,
            DB::table(config('permission.table_names.model_has_permissions'))
                ->where('permission_test_id', $permission->getKey())
                ->count(),
        );
    }

    public function testItDoesDetachRolesAndUsersWhenForceDeleting(): void
    {
        $permissionId = $this->testUserPermission->getKey();
        $this->testUserRole->givePermissionTo($permissionId);
        $this->testUser->givePermissionTo($permissionId);

        DB::enableQueryLog();
        $this->testUserPermission->forceDelete();
        DB::disableQueryLog();

        $this->assertCount(3 + $this->resetDatabaseQuery, DB::getQueryLog());

        $this->assertNull(Permission::withTrashed()->find($permissionId));
        $this->assertSame(
            0,
            DB::table(config('permission.table_names.role_has_permissions'))
                ->where('permission_test_id', $permissionId)
                ->count(),
        );
        $this->assertSame(
            0,
            DB::table(config('permission.table_names.model_has_permissions'))
                ->where('permission_test_id', $permissionId)
                ->count(),
        );
    }

    public function testItTouchesWhenAssigningNewPermissions(): void
    {
        CarbonImmutable::setTestNow('2021-07-19 10:13:14');

        $user = Admin::create(['email' => 'user1@test.com']);
        $permission1 = Permission::create(['name' => 'edit-news', 'guard_name' => 'admin']);
        $permission2 = Permission::create(['name' => 'edit-blog', 'guard_name' => 'admin']);

        $this->assertSame('2021-07-19 10:13:14', $permission1->updated_at->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow('2021-07-20 19:13:14');

        $user->syncPermissions([$permission1->getKey(), $permission2->getKey()]);

        $this->assertSame('2021-07-20 19:13:14', $permission1->refresh()->updated_at->format('Y-m-d H:i:s'));
        $this->assertSame('2021-07-20 19:13:14', $permission2->refresh()->updated_at->format('Y-m-d H:i:s'));
    }
}
