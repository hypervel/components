<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Collection;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;
use WeakReference;

class PartitionRelationProvenanceTest extends PartitionTestCase
{
    public function testLoadedRoleAndPermissionRelationsReloadAfterPartitionSwitch(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $roleA = PartitionedRole::create(['name' => 'role-a']);
        $permissionA = PartitionedPermission::create(['name' => 'permission-a']);
        $user->assignRole($roleA);
        $user->givePermissionTo($permissionA);
        $user->load(['roles', 'permissions']);

        $this->assertTrue($user->hasRole('role-a'));
        $this->assertTrue($user->hasDirectPermission('permission-a'));

        $this->setPartition(self::PARTITION_B);
        $roleB = PartitionedRole::create(['name' => 'role-b']);
        $permissionB = PartitionedPermission::create(['name' => 'permission-b']);
        $writer = GlobalPartitionUser::query()->findOrFail($user->getKey());
        $writer->assignRole($roleB);
        $writer->givePermissionTo($permissionB);

        $this->assertFalse($user->hasRole('role-a'));
        $this->assertTrue($user->hasRole('role-b'));
        $this->assertSame(['permission-b'], $user->getDirectPermissions()->pluck('name')->all());
    }

    public function testEmptyLoadedRelationIsNotReusedInAnotherPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $user->load('roles');

        $this->assertFalse($user->hasRole('member'));

        $this->setPartition(self::PARTITION_B);
        $role = PartitionedRole::create(['name' => 'member']);
        GlobalPartitionUser::query()->findOrFail($user->getKey())->assignRole($role);

        $this->assertTrue($user->hasRole('member'));
    }

    public function testManualRelationReplacementInvalidatesItsProvenanceMarker(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $role = PartitionedRole::create(['name' => 'member']);
        $user->assignRole($role);
        $user->load('roles');

        $user->setRelation('roles', new Collection);

        $this->assertTrue($user->hasRole('member'));
    }

    public function testRelationProvenanceDoesNotRetainDiscardedModelsOrCollections(): void
    {
        $registrar = $this->app->make(PermissionRegistrar::class);

        [$modelReference, $collectionReference] = (function () use ($registrar): array {
            $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
            $role = PartitionedRole::create(['name' => 'member']);
            $user->assignRole($role);
            $user->load('roles');
            $roles = $user->getRelation('roles');

            $this->assertTrue($registrar->loadedRelationIsCurrent($user, 'roles'));

            return [WeakReference::create($user), WeakReference::create($roles)];
        })();

        gc_collect_cycles();

        $this->assertNull($modelReference->get());
        $this->assertNull($collectionReference->get());
    }
}
