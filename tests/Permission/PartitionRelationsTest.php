<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Context\CoroutineContext;
use Hypervel\Database\Events\TransactionBeginning;
use Hypervel\Database\QueryException;
use Hypervel\Permission\Events\PermissionAttachedEvent;
use Hypervel\Permission\Events\PermissionDetachedEvent;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Permission\Exceptions\PermissionPartitionViolation;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedUser;
use Hypervel\Tests\Permission\Fixtures\PartitionContext;

class PartitionRelationsTest extends PartitionTestCase
{
    public function testGlobalSubjectHasIndependentAssignmentsInEachPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleA->givePermissionTo($permissionA);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);

        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $roleB->givePermissionTo($permissionB);
        $user->assignRole($roleB);

        $this->assertTrue($user->hasRole($roleB));
        $this->assertFalse($user->hasDirectPermission($permissionB));
        $this->assertTrue($user->hasPermissionTo($permissionB));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasRole($roleA));
        $this->assertTrue($user->hasDirectPermission($permissionA));
        $this->assertTrue($user->hasPermissionTo($permissionA));
    }

    public function testPublicRelationAttachAddsTheCapturedPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);

        $user->roles()->attach($role);

        $pivot = $user->getConnection()->table(Config::modelHasRolesTable())->first();

        $this->assertSame(self::PARTITION_A, $pivot->workspace_id);
        $this->assertSame($role->getKey(), $pivot->role_test_id);
    }

    public function testPublicRelationAttachRejectsAConflictingPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);

        $this->expectException(PermissionPartitionViolation::class);

        $user->roles()->attach($role, ['workspace_id' => self::PARTITION_B]);
    }

    public function testPublicRelationMutationMethodsPreserveThePartitionInvariant(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $first = PartitionedRole::create(['name' => 'first']);
        $second = PartitionedRole::create(['name' => 'second']);
        $third = PartitionedRole::create(['name' => 'third']);

        $user->roles()->attach([
            $first->getKey() => ['workspace_id' => self::PARTITION_A],
            $second->getKey(),
        ]);

        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $second->getKey()],
            $user->roles()->pluck('roles.id')->all(),
        );

        $user->roles()->toggle([$second->getKey(), $third->getKey()]);

        $this->assertEqualsCanonicalizing(
            [$first->getKey(), $third->getKey()],
            $user->roles()->pluck('roles.id')->all(),
        );

        $user->roles()->syncWithoutDetaching([$second->getKey()]);
        $user->roles()->sync([$second->getKey(), $third->getKey()]);

        $this->assertEqualsCanonicalizing(
            [$second->getKey(), $third->getKey()],
            $user->roles()->pluck('roles.id')->all(),
        );

        $user->roles()->detach($second);

        $this->assertSame([$third->getKey()], $user->roles()->pluck('roles.id')->all());
        $this->assertSame(
            [self::PARTITION_A],
            DB::table(Config::modelHasRolesTable())->distinct()->pluck('workspace_id')->all(),
        );
    }

    public function testBulkPublicAttachRemainsOneInsert(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roles = [
            PartitionedRole::create(['name' => 'first']),
            PartitionedRole::create(['name' => 'second']),
            PartitionedRole::create(['name' => 'third']),
        ];
        DB::enableQueryLog();
        DB::flushQueryLog();

        $user->roles()->attach($roles);

        $inserts = array_filter(
            DB::getQueryLog(),
            static fn (array $query): bool => str_starts_with(strtolower($query['query']), 'insert into "model_has_roles"'),
        );

        $this->assertCount(1, $inserts);
        $this->assertSame(3, DB::table(Config::modelHasRolesTable())->count());
    }

    public function testPivotUpdatesCannotMoveAnExistingEdge(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->permissions()->attach($permission, ['is_forbidden' => false]);

        $this->assertSame(1, $user->permissions()->updateExistingPivot($permission, [
            'workspace_id' => self::PARTITION_A,
            'is_forbidden' => true,
        ]));
        $this->assertTrue((bool) DB::table(Config::modelHasPermissionsTable())->value('is_forbidden'));

        $this->expectException(PermissionPartitionViolation::class);

        $user->permissions()->updateExistingPivot($permission, [
            'workspace_id' => self::PARTITION_B,
            'is_forbidden' => false,
        ]);
    }

    public function testSyncWithPivotValuesRejectsAConflictingPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);

        $this->expectException(PermissionPartitionViolation::class);

        $user->permissions()->syncWithPivotValues(
            [$permission->getKey()],
            ['workspace_id' => self::PARTITION_B, 'is_forbidden' => false],
        );
    }

    public function testSuppliedRoleFromAnotherPartitionIsRejected(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $this->setPartition(self::PARTITION_B);

        $this->expectException(PermissionPartitionViolation::class);

        $user->assignRole($role);
    }

    public function testSuppliedPermissionFromAnotherPartitionIsRejected(): void
    {
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);

        $this->expectException(PermissionPartitionViolation::class);

        $roleB->givePermissionTo($permission);
    }

    public function testHasRoleCollectionRejectsARoleFromAnotherPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $this->setPartition(self::PARTITION_A);

        $this->expectException(PermissionPartitionViolation::class);

        $user->hasRole(collect([$roleB]));
    }

    public function testHasRoleArrayRejectsARoleFromAnotherPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $this->setPartition(self::PARTITION_A);

        $this->expectException(PermissionPartitionViolation::class);

        $user->hasRole([$roleB]);
    }

    public function testHasAnyRoleRejectsARoleFromAnotherPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $this->setPartition(self::PARTITION_A);

        $this->expectException(PermissionPartitionViolation::class);

        $user->hasAnyRole($roleB);
    }

    public function testHasAllRolesRejectsARoleFromAnotherPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $this->setPartition(self::PARTITION_A);

        $this->expectException(PermissionPartitionViolation::class);

        $user->hasAllRoles([$roleB]);
    }

    public function testHasExactRolesRejectsARoleFromAnotherPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $this->setPartition(self::PARTITION_A);

        $this->expectException(PermissionPartitionViolation::class);

        $user->hasExactRoles(collect([$roleB]));
    }

    public function testPartitionBearingSubjectFromAnotherPartitionIsRejected(): void
    {
        $user = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'local@example.com',
        ]);

        $this->setPartition(self::PARTITION_B);
        $role = PartitionedRole::create(['name' => 'member']);

        $this->expectException(PermissionPartitionViolation::class);

        $user->assignRole($role);
    }

    public function testQueuedAssignmentRetainsThePartitionCapturedBeforeSave(): void
    {
        $role = PartitionedRole::create(['name' => 'member']);
        $user = new GlobalPartitionUser(['email' => 'queued@example.com']);
        $user->assignRole($role);

        $this->setPartition(self::PARTITION_B);
        $user->save();

        $this->assertFalse($user->hasRole('member'));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasRole('member'));
        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'role_test_id' => $role->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
    }

    public function testQueuedRolesAndPermissionsRetainMultipleCapturedPartitionsWithoutSaveContext(): void
    {
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user = new GlobalPartitionUser(['email' => 'queued@example.com']);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($roleB);
        $user->giveForbiddenTo($permissionB);

        PartitionContext::forget();
        $user->save();

        $this->setPartition(self::PARTITION_A);
        $this->assertTrue($user->hasRole($roleA));
        $this->assertTrue($user->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);
        $this->assertTrue($user->hasRole($roleB));
        $this->assertTrue($user->hasForbiddenPermission($permissionB));
    }

    public function testUnsavedRoleQueueDoesNotInvalidateWildcardStateBeforeCommit(): void
    {
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $role = new PartitionedRole([
            'name' => 'member',
            'workspace_id' => self::PARTITION_A,
        ]);
        $partition = $this->app->make(PermissionRegistrar::class)->resolvePartition();

        $this->assertNotNull($partition);

        $runtimeKey = 'permission-runtime:partition:' . $partition->cacheSegment() . ':sentinel';
        CoroutineContext::set(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, [
            $runtimeKey => ['cached' => true],
        ]);

        $role->givePermissionTo($permission);

        $this->assertArrayHasKey(
            $runtimeKey,
            CoroutineContext::get(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, []),
        );

        $role->save();

        $this->assertArrayNotHasKey(
            $runtimeKey,
            CoroutineContext::get(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, []),
        );
    }

    public function testMultiPartitionDeferredQueuesInvalidateStoredContextsOnlyAfterCommit(): void
    {
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user = new GlobalPartitionUser(['email' => 'queued-cache@example.com']);
        $user->setAttribute('id', '00000000-0000-4000-8000-000000000099');
        $registrar = $this->app->make(PermissionRegistrar::class);
        $partitionA = $registrar->resolvePartition();

        $this->assertNotNull($partitionA);

        $runtimeKeyA = $this->runtimeAssignmentCacheKey($registrar, $user, $partitionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $partitionB = $registrar->resolvePartition();

        $this->assertNotNull($partitionB);

        $runtimeKeyB = $this->runtimeAssignmentCacheKey($registrar, $user, $partitionB);
        CoroutineContext::set(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, [
            $runtimeKeyA => ['cached' => 'a'],
            $runtimeKeyB => ['cached' => 'b'],
        ]);

        $this->setPartition(self::PARTITION_A);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $user->assignRole($roleB);
        $user->giveForbiddenTo($permissionB);

        $this->assertSame(
            [$runtimeKeyA, $runtimeKeyB],
            array_keys(CoroutineContext::get(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, [])),
        );

        PartitionContext::forget();
        $user->save();

        $this->assertSame(
            [],
            CoroutineContext::get(PermissionRegistrar::WILDCARD_PERMISSION_INDEX_CONTEXT_KEY, []),
        );
    }

    public function testQueuedAssignmentBatchesRollBackTogetherAndRetryExactlyOnce(): void
    {
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user = new GlobalPartitionUser(['email' => 'retry@example.com']);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $tokenA = $registrar->modelAssignmentCacheToken();

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($roleB);
        $user->giveForbiddenTo($permissionB);
        $tokenB = $registrar->modelAssignmentCacheToken();
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([
            RoleAttachedEvent::class,
            RoleDetachedEvent::class,
            PermissionAttachedEvent::class,
            PermissionDetachedEvent::class,
        ]);

        DB::unprepared(sprintf(
            <<<'SQL'
CREATE TRIGGER fail_queued_permission_assignment
BEFORE INSERT ON "model_has_permissions"
WHEN NEW."permission_test_id" = '%s'
BEGIN
    SELECT RAISE(ABORT, 'forced queued permission assignment failure');
END
SQL,
            $permissionB->getKey(),
        ));

        PartitionContext::forget();

        try {
            $user->save();
            $this->fail('Expected the forced queued permission assignment failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_queued_permission_assignment');
        }

        $this->assertTrue($user->exists);
        $this->assertDatabaseHas('global_partition_users', ['id' => $user->getKey()]);
        $this->assertSame(0, DB::table(Config::modelHasRolesTable())->count());
        $this->assertSame(0, DB::table(Config::modelHasPermissionsTable())->count());

        $this->setPartition(self::PARTITION_A);
        $this->assertSame($tokenA, $registrar->modelAssignmentCacheToken());
        $this->setPartition(self::PARTITION_B);
        $this->assertSame($tokenB, $registrar->modelAssignmentCacheToken());

        Event::assertNotDispatched(RoleAttachedEvent::class);
        Event::assertNotDispatched(RoleDetachedEvent::class);
        Event::assertNotDispatched(PermissionAttachedEvent::class);
        Event::assertNotDispatched(PermissionDetachedEvent::class);

        PartitionContext::forget();
        $user->save();

        $this->assertSame(2, DB::table(Config::modelHasRolesTable())->count());
        $this->assertSame(2, DB::table(Config::modelHasPermissionsTable())->count());

        $this->setPartition(self::PARTITION_A);
        $this->assertTrue($user->hasRole($roleA));
        $this->assertTrue($user->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);
        $this->assertTrue($user->hasRole($roleB));
        $this->assertTrue($user->hasForbiddenPermission($permissionB));
    }

    public function testUnsavedSyncReplacesOnlyTheCapturedPartitionQueue(): void
    {
        $firstRoleA = PartitionedRole::create(['name' => 'first']);
        $secondRoleA = PartitionedRole::create(['name' => 'second']);
        $firstPermissionA = PartitionedPermission::create(['name' => 'first']);
        $secondPermissionA = PartitionedPermission::create(['name' => 'second']);
        $user = new GlobalPartitionUser(['email' => 'queued@example.com']);
        $user->assignRole($firstRoleA);
        $user->givePermissionTo($firstPermissionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($roleB);
        $user->givePermissionTo($permissionB);

        $this->setPartition(self::PARTITION_A);
        $user->syncRoles($secondRoleA);
        $user->syncPermissions($secondPermissionA);

        PartitionContext::forget();
        $user->save();

        $this->setPartition(self::PARTITION_A);
        $this->assertFalse($user->hasRole($firstRoleA));
        $this->assertTrue($user->hasRole($secondRoleA));
        $this->assertFalse($user->hasDirectPermission($firstPermissionA));
        $this->assertTrue($user->hasDirectPermission($secondPermissionA));

        $this->setPartition(self::PARTITION_B);
        $this->assertTrue($user->hasRole($roleB));
        $this->assertTrue($user->hasDirectPermission($permissionB));
    }

    public function testUnsavedRemovalReconcilesOnlyTheCapturedPartitionQueue(): void
    {
        $roleA = PartitionedRole::create(['name' => 'member']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $user = new GlobalPartitionUser(['email' => 'queued@example.com']);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $user->assignRole($roleB);
        $user->giveForbiddenTo($permissionB);

        $this->setPartition(self::PARTITION_A);
        $user->removeRole($roleA);
        $user->revokePermissionTo($permissionA);

        PartitionContext::forget();
        $user->save();

        $this->setPartition(self::PARTITION_A);
        $this->assertFalse($user->hasRole($roleA));
        $this->assertFalse($user->hasDirectPermission($permissionA));

        $this->setPartition(self::PARTITION_B);
        $this->assertTrue($user->hasRole($roleB));
        $this->assertTrue($user->hasForbiddenPermission($permissionB));
    }

    public function testEmptyAddsStillValidateAPartitionBearingSubject(): void
    {
        $user = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'local@example.com',
        ]);
        $this->setPartition(self::PARTITION_B);

        $this->expectException(PermissionPartitionViolation::class);

        $user->assignRole();
    }

    public function testEmptyPermissionAddsStillValidateAPartitionBearingSubject(): void
    {
        $user = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'local@example.com',
        ]);
        $this->setPartition(self::PARTITION_B);

        $this->expectException(PermissionPartitionViolation::class);

        $user->givePermissionTo();
    }

    public function testRoleSynchronizationRollsBackEveryWriteWhenABatchFails(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $current = PartitionedRole::create(['name' => 'current']);
        $failing = PartitionedRole::create(['name' => 'failing']);
        $user->assignRole($current);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $this->assertTrue($user->hasRole($current));
        $cacheKey = $this->assignmentCacheKey(
            $registrar,
            PermissionRegistrar::MODEL_ROLES_CACHE_KEY_PREFIX,
            $user,
        );
        $cachedAssignments = $registrar->getCacheRepository()->get($cacheKey);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleAttachedEvent::class, RoleDetachedEvent::class]);

        DB::unprepared(sprintf(
            <<<'SQL'
CREATE TRIGGER fail_role_assignment
BEFORE INSERT ON "model_has_roles"
WHEN NEW."role_test_id" = '%s'
BEGIN
    SELECT RAISE(ABORT, 'forced role assignment failure');
END
SQL,
            $failing->getKey(),
        ));

        try {
            $user->syncRoles($failing);
            $this->fail('Expected the forced role assignment failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_role_assignment');
        }

        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'role_test_id' => $current->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertDatabaseMissing(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'role_test_id' => $failing->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        Event::assertNotDispatched(RoleAttachedEvent::class);
        Event::assertNotDispatched(RoleDetachedEvent::class);
        $this->assertTrue($registrar->getCacheRepository()->has($cacheKey));
        $this->assertSame($cachedAssignments, $registrar->getCacheRepository()->get($cacheKey));

        $user->syncRoles($failing);

        $this->assertFalse($user->hasRole($current));
        $this->assertTrue($user->hasRole($failing));
    }

    public function testPermissionSynchronizationRollsBackEveryWriteWhenABatchFails(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $current = PartitionedPermission::create(['name' => 'current']);
        $allowed = PartitionedPermission::create(['name' => 'allowed']);
        $failing = PartitionedPermission::create(['name' => 'failing']);
        $user->givePermissionTo($current);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $this->assertTrue($user->hasDirectPermission($current));
        $cacheKey = $this->assignmentCacheKey(
            $registrar,
            PermissionRegistrar::MODEL_PERMISSIONS_CACHE_KEY_PREFIX,
            $user,
        );
        $cachedAssignments = $registrar->getCacheRepository()->get($cacheKey);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([PermissionAttachedEvent::class, PermissionDetachedEvent::class]);

        DB::unprepared(sprintf(
            <<<'SQL'
CREATE TRIGGER fail_permission_assignment
BEFORE INSERT ON "model_has_permissions"
WHEN NEW."permission_test_id" = '%s'
BEGIN
    SELECT RAISE(ABORT, 'forced permission assignment failure');
END
SQL,
            $failing->getKey(),
        ));

        try {
            $user->syncPermissionsWithForbidden(
                allowed: [$allowed],
                forbidden: [$failing],
            );
            $this->fail('Expected the forced permission assignment failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_permission_assignment');
        }

        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'permission_test_id' => $current->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertDatabaseMissing(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'permission_test_id' => $allowed->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertDatabaseMissing(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'permission_test_id' => $failing->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        Event::assertNotDispatched(PermissionAttachedEvent::class);
        Event::assertNotDispatched(PermissionDetachedEvent::class);
        $this->assertTrue($registrar->getCacheRepository()->has($cacheKey));
        $this->assertSame($cachedAssignments, $registrar->getCacheRepository()->get($cacheKey));

        $user->syncPermissionsWithForbidden(
            allowed: [$allowed],
            forbidden: [$failing],
        );

        $this->assertFalse($user->hasDirectPermission($current));
        $this->assertTrue($user->hasDirectPermission($allowed));
        $this->assertTrue($user->hasForbiddenPermission($failing));
    }

    public function testApplicationTransactionRollsBackRoleAssignmentWhenTouchingTheRelatedModelsFails(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $user->setTouchedRelations(['roles']);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse($user->hasRole($role));

        $cacheKey = $this->assignmentCacheKey(
            $registrar,
            PermissionRegistrar::MODEL_ROLES_CACHE_KEY_PREFIX,
            $user,
        );
        $cachedAssignments = $registrar->getCacheRepository()->get($cacheKey);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleAttachedEvent::class]);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_role_assignment_touch
BEFORE UPDATE ON "roles"
BEGIN
    SELECT RAISE(ABORT, 'forced role assignment touch failure');
END
SQL);

        try {
            DB::transaction(fn () => $user->assignRole($role));
            $this->fail('Expected the forced role assignment touch failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_role_assignment_touch');
        }

        $this->assertDatabaseMissing(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'role_test_id' => $role->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertSame($cachedAssignments, $registrar->getCacheRepository()->get($cacheKey));
        Event::assertNotDispatched(RoleAttachedEvent::class);

        $user->assignRole($role);

        $this->assertTrue($user->hasRole($role));
    }

    public function testApplicationTransactionRollsBackRoleRemovalWhenTouchingTheRelatedModelsFails(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $removedRole = PartitionedRole::create(['name' => 'removed']);
        $retainedRole = PartitionedRole::create(['name' => 'retained']);
        $user->assignRole($removedRole, $retainedRole);
        $user->setTouchedRelations(['roles']);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($user->hasRole($removedRole));

        $cacheKey = $this->assignmentCacheKey(
            $registrar,
            PermissionRegistrar::MODEL_ROLES_CACHE_KEY_PREFIX,
            $user,
        );
        $cachedAssignments = $registrar->getCacheRepository()->get($cacheKey);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([RoleDetachedEvent::class]);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_role_removal_touch
BEFORE UPDATE ON "roles"
BEGIN
    SELECT RAISE(ABORT, 'forced role removal touch failure');
END
SQL);

        try {
            DB::transaction(fn () => $user->removeRole($removedRole));
            $this->fail('Expected the forced role removal touch failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_role_removal_touch');
        }

        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'role_test_id' => $removedRole->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'workspace_id' => self::PARTITION_A,
            'role_test_id' => $retainedRole->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertSame($cachedAssignments, $registrar->getCacheRepository()->get($cacheKey));
        Event::assertNotDispatched(RoleDetachedEvent::class);

        $user->removeRole($removedRole);

        $this->assertFalse($user->hasRole($removedRole));
        $this->assertTrue($user->hasRole($retainedRole));
    }

    public function testApplicationTransactionRollsBackPermissionRevocationWhenTouchingTheRelatedModelsFails(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $revokedPermission = PartitionedPermission::create(['name' => 'revoked']);
        $retainedPermission = PartitionedPermission::create(['name' => 'retained']);
        $user->givePermissionTo($revokedPermission, $retainedPermission);
        $user->setTouchedRelations(['permissions']);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertTrue($user->hasDirectPermission($revokedPermission));

        $cacheKey = $this->assignmentCacheKey(
            $registrar,
            PermissionRegistrar::MODEL_PERMISSIONS_CACHE_KEY_PREFIX,
            $user,
        );
        $cachedAssignments = $registrar->getCacheRepository()->get($cacheKey);
        $this->app->make('config')->set('permission.events_enabled', true);
        Event::fake([PermissionDetachedEvent::class]);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER fail_permission_revocation_touch
BEFORE UPDATE ON "permissions"
BEGIN
    SELECT RAISE(ABORT, 'forced permission revocation touch failure');
END
SQL);

        try {
            DB::transaction(fn () => $user->revokePermissionTo($revokedPermission));
            $this->fail('Expected the forced permission revocation touch failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_permission_revocation_touch');
        }

        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'permission_test_id' => $revokedPermission->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertDatabaseHas(Config::modelHasPermissionsTable(), [
            'workspace_id' => self::PARTITION_A,
            'permission_test_id' => $retainedPermission->getKey(),
            'model_test_id' => $user->getKey(),
        ]);
        $this->assertSame($cachedAssignments, $registrar->getCacheRepository()->get($cacheKey));
        Event::assertNotDispatched(PermissionDetachedEvent::class);

        $user->revokePermissionTo($revokedPermission);

        $this->assertFalse($user->hasDirectPermission($revokedPermission));
        $this->assertTrue($user->hasDirectPermission($retainedPermission));
    }

    public function testReverseAssignmentHelpersStayInsideTheCapturedPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $roleA->assignToModels($user);

        $this->assertTrue($user->hasRole($roleA));

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $roleB->assignToModels($user->getKey(), GlobalPartitionUser::class);

        $this->assertTrue($user->hasRole($roleB));

        $roleB->removeFromModels($user);

        $this->assertFalse($user->hasRole($roleB));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasRole($roleA));
    }

    public function testReverseAssignmentHelpersUseTransactionsOnlyForSeveralMorphGroups(): void
    {
        $globalUser = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $partitionedUser = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'partitioned@example.com',
        ]);
        $role = PartitionedRole::create(['name' => 'member']);

        Event::fake([TransactionBeginning::class]);

        $role->assignToModels($globalUser);
        $role->removeFromModels($globalUser);

        Event::assertNotDispatched(TransactionBeginning::class);

        Event::fake([TransactionBeginning::class]);

        $role->assignToModels([$globalUser, $partitionedUser]);

        Event::assertDispatchedTimes(TransactionBeginning::class, 1);
    }

    public function testReverseAssignmentRollsBackEarlierMorphGroupsAndInvalidation(): void
    {
        $globalUser = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $partitionedUser = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'partitioned@example.com',
        ]);
        $role = PartitionedRole::create(['name' => 'member']);
        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse($globalUser->hasRole($role));

        $cacheKey = $this->assignmentCacheKey(
            $registrar,
            PermissionRegistrar::MODEL_ROLES_CACHE_KEY_PREFIX,
            $globalUser,
        );
        $cachedAssignments = $registrar->getCacheRepository()->get($cacheKey);

        DB::unprepared(sprintf(
            <<<'SQL'
CREATE TRIGGER fail_reverse_role_assignment
BEFORE INSERT ON "model_has_roles"
WHEN NEW."model_type" = '%s'
BEGIN
    SELECT RAISE(ABORT, 'forced reverse role assignment failure');
END
SQL,
            $partitionedUser->getMorphClass(),
        ));

        try {
            $role->assignToModels([$globalUser, $partitionedUser]);
            $this->fail('Expected the forced reverse role assignment failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_reverse_role_assignment');
        }

        $this->assertSame(0, DB::table(Config::modelHasRolesTable())->count());
        $this->assertTrue($registrar->getCacheRepository()->has($cacheKey));
        $this->assertSame($cachedAssignments, $registrar->getCacheRepository()->get($cacheKey));

        $role->assignToModels([$globalUser, $partitionedUser]);

        $this->assertSame(2, DB::table(Config::modelHasRolesTable())->count());
    }

    public function testReverseRemovalRollsBackEarlierMorphGroups(): void
    {
        $globalUser = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $partitionedUser = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'partitioned@example.com',
        ]);
        $role = PartitionedRole::create(['name' => 'member']);
        $role->assignToModels([$globalUser, $partitionedUser]);

        DB::unprepared(sprintf(
            <<<'SQL'
CREATE TRIGGER fail_reverse_role_removal
BEFORE DELETE ON "model_has_roles"
WHEN OLD."model_type" = '%s'
BEGIN
    SELECT RAISE(ABORT, 'forced reverse role removal failure');
END
SQL,
            $partitionedUser->getMorphClass(),
        ));

        try {
            $role->removeFromModels([$globalUser, $partitionedUser]);
            $this->fail('Expected the forced reverse role removal failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_reverse_role_removal');
        }

        $this->assertSame(2, DB::table(Config::modelHasRolesTable())->count());

        $role->removeFromModels([$globalUser, $partitionedUser]);

        $this->assertSame(0, DB::table(Config::modelHasRolesTable())->count());
    }

    public function testReverseSynchronizationPreservesTheOldGraphWhenReplacementFails(): void
    {
        $currentUser = GlobalPartitionUser::create(['email' => 'current@example.com']);
        $replacementUser = GlobalPartitionUser::create(['email' => 'replacement@example.com']);
        $partitionedUser = PartitionedUser::create([
            'workspace_id' => self::PARTITION_A,
            'email' => 'partitioned@example.com',
        ]);
        $role = PartitionedRole::create(['name' => 'member']);
        $role->assignToModels($currentUser);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $token = $registrar->modelAssignmentCacheToken();

        DB::unprepared(sprintf(
            <<<'SQL'
CREATE TRIGGER fail_reverse_role_sync
BEFORE INSERT ON "model_has_roles"
WHEN NEW."model_type" = '%s'
BEGIN
    SELECT RAISE(ABORT, 'forced reverse role sync failure');
END
SQL,
            $partitionedUser->getMorphClass(),
        ));

        try {
            $role->syncModels([$replacementUser, $partitionedUser]);
            $this->fail('Expected the forced reverse role sync failure.');
        } catch (QueryException) {
        } finally {
            DB::unprepared('DROP TRIGGER fail_reverse_role_sync');
        }

        $this->assertDatabaseHas(Config::modelHasRolesTable(), [
            'role_test_id' => $role->getKey(),
            'model_test_id' => $currentUser->getKey(),
            'model_type' => $currentUser->getMorphClass(),
        ]);
        $this->assertDatabaseMissing(Config::modelHasRolesTable(), [
            'role_test_id' => $role->getKey(),
            'model_test_id' => $replacementUser->getKey(),
            'model_type' => $replacementUser->getMorphClass(),
        ]);
        $this->assertSame($token, $registrar->modelAssignmentCacheToken());

        $role->syncModels([$replacementUser, $partitionedUser]);

        $this->assertDatabaseMissing(Config::modelHasRolesTable(), [
            'role_test_id' => $role->getKey(),
            'model_test_id' => $currentUser->getKey(),
            'model_type' => $currentUser->getMorphClass(),
        ]);
        $this->assertSame(2, DB::table(Config::modelHasRolesTable())->count());
    }

    public function testNestedEagerRelationsConstructAndHydrateInTheCapturedPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $role->givePermissionTo($permission);
        $user->assignRole($role);

        $loaded = GlobalPartitionUser::query()
            ->with('roles.permissions')
            ->findOrFail($user->getKey());

        $this->assertTrue($loaded->relationLoaded('roles'));
        $this->assertTrue($loaded->roles->first()->relationLoaded('permissions'));
        $this->assertSame(['articles.edit'], $loaded->roles->first()->permissions->pluck('name')->all());
    }

    public function testRelationExistenceQueriesStayInsideTheCurrentPartition(): void
    {
        $userA = GlobalPartitionUser::create(['email' => 'a@example.com']);
        $userB = GlobalPartitionUser::create(['email' => 'b@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $userA->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $userB->assignRole($roleB);

        $this->assertSame(
            [$userB->getKey()],
            GlobalPartitionUser::query()->whereHas('roles')->pluck('id')->all(),
        );

        $this->setPartition(self::PARTITION_A);

        $this->assertSame(
            [$userA->getKey()],
            GlobalPartitionUser::query()->whereHas('roles')->pluck('id')->all(),
        );
    }

    public function testAllRelationExistenceShapesStayInsideTheCurrentPartition(): void
    {
        $userA = GlobalPartitionUser::create(['email' => 'a@example.com']);
        $userB = GlobalPartitionUser::create(['email' => 'b@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $userA->assignRole($roleA);

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'member']);
        $userB->assignRole($roleB);

        $this->assertSame([$userB->getKey()], GlobalPartitionUser::query()->has('roles')->pluck('id')->all());
        $this->assertSame([$userA->getKey()], GlobalPartitionUser::query()->whereDoesntHave('roles')->pluck('id')->all());
        $this->assertSame(1, GlobalPartitionUser::query()->withCount('roles')->findOrFail($userB->getKey())->roles_count);
        $this->assertSame(0, GlobalPartitionUser::query()->withCount('roles')->findOrFail($userA->getKey())->roles_count);
    }

    public function testNarrowedPersistedRoleCannotConstructRelations(): void
    {
        PartitionedRole::create(['name' => 'member']);
        $role = PartitionedRole::query()->select(['id', 'name'])->firstOrFail();

        $this->expectException(PermissionPartitionViolation::class);

        $role->permissions();
    }

    public function testUnsavedRoleWithConflictingPartitionCannotConstructRelations(): void
    {
        $role = new PartitionedRole([
            'name' => 'member',
            'workspace_id' => self::PARTITION_B,
        ]);

        $this->expectException(PermissionPartitionViolation::class);

        $role->permissions();
    }

    public function testAttributeLessUnsavedRoleCanDefineAConstrainedRelation(): void
    {
        $role = new PartitionedRole(['name' => 'member']);
        $relation = $role->permissions();

        $this->assertStringContainsString('workspace_id', $relation->toSql());
        $this->assertContains(self::PARTITION_A, $relation->getBindings());
    }

    /**
     * Build an exact assignment cache key for rollback assertions.
     */
    private function assignmentCacheKey(
        PermissionRegistrar $registrar,
        string $prefix,
        GlobalPartitionUser $user,
    ): string {
        $partition = $registrar->resolvePartition();

        $this->assertNotNull($partition);

        return implode(':', [
            $prefix . ':partition:' . $partition->cacheSegment(),
            PermissionPartition::encodeCacheSegment($registrar->modelAssignmentCacheToken($partition)),
            PermissionPartition::encodeCacheSegment($user->getMorphClass()),
            PermissionPartition::encodeCacheSegment((string) $user->getKey()),
            PermissionPartition::encodeCacheSegment(null),
        ]);
    }

    /**
     * Build an exact coroutine-local assignment cache key.
     */
    private function runtimeAssignmentCacheKey(
        PermissionRegistrar $registrar,
        GlobalPartitionUser $user,
        PermissionPartition $partition,
    ): string {
        return implode(':', [
            'permission-runtime:partition:' . $partition->cacheSegment(),
            PermissionPartition::encodeCacheSegment($registrar->modelAssignmentCacheToken($partition)),
            PermissionPartition::encodeCacheSegment($user->getMorphClass()),
            PermissionPartition::encodeCacheSegment((string) $user->getKey()),
            PermissionPartition::encodeCacheSegment(null),
        ]);
    }
}
