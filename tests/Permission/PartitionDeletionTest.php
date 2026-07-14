<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\QueryException;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionPermissionsOnlyUser;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\Models\SoftDeletingGlobalPartitionUser;

class PartitionDeletionTest extends PartitionTestCase
{
    public function testRoleDeleteRemovesOnlyItsPartitionEdges(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleA->givePermissionTo($permissionA);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleB->givePermissionTo($permissionB);
        $user->assignRole($roleB);

        $this->setPartition(self::PARTITION_A);
        $roleA->delete();

        $this->assertFalse($user->hasRole('member'));

        $this->setPartition(self::PARTITION_B);

        $this->assertTrue($user->hasRole('member'));
        $this->assertTrue($user->hasPermissionTo('articles.edit'));
        $this->assertSame(1, DB::table('model_has_roles')->count());
        $this->assertSame(1, DB::table('role_has_permissions')->count());
    }

    public function testPermissionDeleteRemovesOnlyItsPartitionEdges(): void
    {
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleA->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleB->givePermissionTo($permissionB);

        $this->setPartition(self::PARTITION_A);
        $permissionA->delete();

        $this->assertSame(1, DB::table('role_has_permissions')->count());

        $this->setPartition(self::PARTITION_B);

        $this->assertSame($permissionB->getKey(), $roleB->permissions()->sole()->getKey());
    }

    public function testSubjectDeleteDiscoversEachAssignmentTableAndDeletesAllPartitions(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $writer = GlobalPartitionUser::query()->findOrFail($user->getKey());
        $writer->assignRole($roleB);
        $writer->givePermissionTo($permissionB);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $user->delete();

        $discoveryQueries = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'select distinct')
                && (str_contains($query['query'], 'model_has_roles')
                    || str_contains($query['query'], 'model_has_permissions')),
        );
        $roleDeletes = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'delete')
                && str_contains($query['query'], 'model_has_roles'),
        );
        $permissionDeletes = array_filter(
            DB::getQueryLog(),
            fn (array $query): bool => str_starts_with($query['query'], 'delete')
                && str_contains($query['query'], 'model_has_permissions'),
        );

        $this->assertCount(2, $discoveryQueries);
        $this->assertCount(1, $roleDeletes);
        $this->assertCount(1, $permissionDeletes);
        $this->assertSame(0, DB::table('model_has_roles')->count());
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    public function testHasPermissionsOnlySubjectCleanupInvalidatesEveryPartition(): void
    {
        $user = GlobalPartitionPermissionsOnlyUser::create(['email' => 'global@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->givePermissionTo($permissionA);
        $readerA = GlobalPartitionPermissionsOnlyUser::query()->findOrFail($user->getKey());

        $this->assertTrue($readerA->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $writerB = GlobalPartitionPermissionsOnlyUser::query()->findOrFail($user->getKey());
        $writerB->givePermissionTo($permissionB);
        $readerB = GlobalPartitionPermissionsOnlyUser::query()->findOrFail($user->getKey());

        $this->assertTrue($readerB->hasDirectPermission($permissionB));

        $this->setPartition(self::PARTITION_A);
        $user->delete();

        $this->assertFalse($readerA->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);

        $this->assertFalse($readerB->hasDirectPermission($permissionB));
        $this->assertSame(0, DB::table('model_has_permissions')->count());
    }

    public function testSoftDeletingASubjectPreservesAssignmentsUntilForceDeletion(): void
    {
        $user = SoftDeletingGlobalPartitionUser::create(['email' => 'soft-delete@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $writerB = SoftDeletingGlobalPartitionUser::query()->findOrFail($user->getKey());
        $writerB->assignRole($roleB);
        $writerB->givePermissionTo($permissionB);

        $this->setPartition(self::PARTITION_A);
        $user->delete();

        $this->assertSame(2, DB::table('model_has_roles')->count());
        $this->assertSame(2, DB::table('model_has_permissions')->count());

        $user->forceDelete();

        $this->assertSame(0, DB::table('model_has_roles')->count());
        $this->assertSame(0, DB::table('model_has_permissions')->count());
        $this->assertDatabaseMissing('global_partition_users', ['id' => $user->getKey()]);
    }

    public function testRolePivotCleanupRollsBackWhenTheSecondDeleteFails(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($role);
        $role->givePermissionTo($permission);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_role_permission_cleanup
BEFORE DELETE ON "role_has_permissions"
BEGIN
    SELECT RAISE(ABORT, 'forced role permission cleanup failure');
END
SQL);

        try {
            $role->delete();
            $this->fail('Expected the forced role permission cleanup failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_role_permission_cleanup');
        }

        $this->assertDatabaseHas('roles', ['id' => $role->getKey()]);
        $this->assertSame(1, DB::table('model_has_roles')->count());
        $this->assertSame(1, DB::table('role_has_permissions')->count());
    }

    public function testDeleteOrFailRollsBackPivotCleanupWithTheSubjectRow(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($role);
        $user->givePermissionTo($permission);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_subject_row_delete
BEFORE DELETE ON "global_partition_users"
BEGIN
    SELECT RAISE(ABORT, 'forced subject row delete failure');
END
SQL);

        try {
            $user->deleteOrFail();
            $this->fail('Expected the forced subject row delete failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_subject_row_delete');
        }

        $this->assertDatabaseHas('global_partition_users', ['id' => $user->getKey()]);
        $this->assertSame(1, DB::table('model_has_roles')->count());
        $this->assertSame(1, DB::table('model_has_permissions')->count());
    }
}
