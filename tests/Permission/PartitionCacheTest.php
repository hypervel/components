<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionPartition;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;

class PartitionCacheTest extends PartitionTestCase
{
    public function testCatalogCacheKeysAndPayloadsArePartitioned(): void
    {
        PartitionedRole::create(['name' => 'role-a']);
        PartitionedPermission::create(['name' => 'permission-a']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getPermissions();
        $keyA = $registrar->getCacheKey();

        $this->setPartition(self::PARTITION_B);
        PartitionedRole::create(['name' => 'role-b']);
        PartitionedPermission::create(['name' => 'permission-b']);
        $registrar->getPermissions();
        $keyB = $registrar->getCacheKey();

        $this->assertNotSame($keyA, $keyB);
        $this->assertStringContainsString(self::PARTITION_A, $keyA);
        $this->assertStringContainsString(self::PARTITION_B, $keyB);

        $payloadA = $registrar->getCacheRepository()->get($keyA);
        $payloadB = $registrar->getCacheRepository()->get($keyB);

        $this->assertSame(['permission-a'], collect($payloadA['permissions'])->pluck('attributes.name')->all());
        $this->assertSame(['permission-b'], collect($payloadB['permissions'])->pluck('attributes.name')->all());
        $this->assertSame(['role-a'], collect($payloadA['roles'])->pluck('attributes.name')->all());
        $this->assertSame(['role-b'], collect($payloadB['roles'])->pluck('attributes.name')->all());
    }

    public function testCatalogMutationInvalidatesOnlyItsRecordPartition(): void
    {
        $roleA = PartitionedRole::create(['name' => 'role-a']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getPermissions();
        $keyA = $registrar->getCacheKey();
        $tokenA = $registrar->modelAssignmentCacheToken();

        $this->setPartition(self::PARTITION_B);
        PartitionedRole::create(['name' => 'role-b']);
        $registrar->getPermissions();
        $keyB = $registrar->getCacheKey();
        $tokenB = $registrar->modelAssignmentCacheToken();

        $this->setPartition(self::PARTITION_A);
        $roleA->name = 'role-a-updated';
        $roleA->save();

        $this->assertFalse($registrar->getCacheRepository()->has($keyA));
        $this->assertTrue($registrar->getCacheRepository()->has($keyB));
        $this->assertNotSame($tokenA, $registrar->modelAssignmentCacheToken());

        $this->setPartition(self::PARTITION_B);

        $this->assertSame($tokenB, $registrar->modelAssignmentCacheToken());
    }

    public function testExactSubjectMutationDoesNotBumpPartitionToken(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $permission = PartitionedPermission::create(['name' => 'articles.edit']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $token = $registrar->modelAssignmentCacheToken();

        $this->assertFalse($user->hasRole('member'));
        $this->assertFalse($user->hasPermissionTo('articles.edit'));

        $user->assignRole($role);
        $user->givePermissionTo($permission);

        $this->assertSame($token, $registrar->modelAssignmentCacheToken());
        $this->assertTrue($user->hasRole('member'));
        $this->assertTrue($user->hasDirectPermission('articles.edit'));
    }

    public function testReverseSyncBumpsOnlyTheCapturedPartitionToken(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'member']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $tokenA = $registrar->modelAssignmentCacheToken();

        $this->setPartition(self::PARTITION_B);
        PartitionedRole::create(['name' => 'member']);
        $tokenB = $registrar->modelAssignmentCacheToken();

        $this->setPartition(self::PARTITION_A);
        $roleA->syncModels($user);

        $this->assertNotSame($tokenA, $registrar->modelAssignmentCacheToken());

        $this->setPartition(self::PARTITION_B);

        $this->assertSame($tokenB, $registrar->modelAssignmentCacheToken());
    }

    public function testCacheResetClearsOnlyTheAmbientPartition(): void
    {
        PartitionedRole::create(['name' => 'role-a']);
        $registrar = $this->app->make(PermissionRegistrar::class);
        $registrar->getPermissions();
        $keyA = $registrar->getCacheKey();

        $this->setPartition(self::PARTITION_B);
        PartitionedRole::create(['name' => 'role-b']);
        $registrar->getPermissions();
        $keyB = $registrar->getCacheKey();

        $registrar->forgetCachedPermissions();

        $this->assertTrue($registrar->getCacheRepository()->has($keyA));
        $this->assertFalse($registrar->getCacheRepository()->has($keyB));
    }

    public function testCacheSegmentsCannotCollideAcrossSeparatorsOrNullValues(): void
    {
        $first = new PermissionPartition('workspace_id', 'a:b');
        $second = new PermissionPartition('workspace_id:a', 'b');

        $this->assertNotSame($first->cacheSegment(), $second->cacheSegment());
        $this->assertNotSame(
            PermissionPartition::encodeCacheSegment(null),
            PermissionPartition::encodeCacheSegment('n:'),
        );
        $this->assertSame(
            PermissionPartition::encodeCacheSegment(1),
            PermissionPartition::encodeCacheSegment('1'),
        );
    }
}
