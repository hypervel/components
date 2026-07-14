<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Tests\Permission\Fixtures\Models\GlobalPartitionUser;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedPermission;
use Hypervel\Tests\Permission\Fixtures\Models\PartitionedRole;

class PartitionAuthorizationTest extends PartitionTestCase
{
    protected function defineEnvironment(ApplicationContract $app): void
    {
        parent::defineEnvironment($app);

        $app->make('config')->set('permission.enable_wildcard_permission', true);
    }

    public function testGuardsRemainIndependentInsideEachPartition(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $webPermissionA = PartitionedPermission::create(['name' => 'articles.edit', 'guard_name' => 'web']);
        $apiPermissionA = PartitionedPermission::create(['name' => 'articles.edit', 'guard_name' => 'api']);
        $webRoleA = PartitionedRole::create(['name' => 'editor', 'guard_name' => 'web']);
        $apiRoleA = PartitionedRole::create(['name' => 'editor', 'guard_name' => 'api']);
        $webRoleA->givePermissionTo($webPermissionA);
        $apiRoleA->giveForbiddenTo($apiPermissionA);
        $user->assignRole($webRoleA, $apiRoleA);

        $this->assertTrue($user->hasPermissionTo('articles.edit', 'web'));
        $this->assertFalse($user->hasPermissionTo('articles.edit', 'api'));

        $this->setPartition(self::PARTITION_B);
        $webPermissionB = PartitionedPermission::create(['name' => 'articles.edit', 'guard_name' => 'web']);
        $apiPermissionB = PartitionedPermission::create(['name' => 'articles.edit', 'guard_name' => 'api']);
        $webRoleB = PartitionedRole::create(['name' => 'editor', 'guard_name' => 'web']);
        $apiRoleB = PartitionedRole::create(['name' => 'editor', 'guard_name' => 'api']);
        $webRoleB->giveForbiddenTo($webPermissionB);
        $apiRoleB->givePermissionTo($apiPermissionB);
        $user->assignRole($webRoleB, $apiRoleB);

        $this->assertFalse($user->hasPermissionTo('articles.edit', 'web'));
        $this->assertTrue($user->hasPermissionTo('articles.edit', 'api'));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasPermissionTo('articles.edit', 'web'));
        $this->assertFalse($user->hasPermissionTo('articles.edit', 'api'));
    }

    public function testDirectAndInheritedForbiddenPermissionsArePartitionIsolated(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $allowedRoleA = PartitionedRole::create(['name' => 'allowed']);
        $allowedRoleA->givePermissionTo($permissionA);
        $user->assignRole($allowedRoleA);
        $user->giveForbiddenTo($permissionA);

        $this->assertTrue($user->hasForbiddenPermission($permissionA));
        $this->assertFalse($user->hasPermissionTo($permissionA));

        $this->setPartition(self::PARTITION_B);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $forbiddenRoleB = PartitionedRole::create(['name' => 'forbidden']);
        $forbiddenRoleB->giveForbiddenTo($permissionB);
        $user->givePermissionTo($permissionB);
        $user->assignRole($forbiddenRoleB);

        $this->assertTrue($user->hasDirectPermission($permissionB));
        $this->assertTrue($user->hasForbiddenPermissionViaRoles($permissionB));
        $this->assertFalse($user->hasPermissionTo($permissionB));

        $user->removeRole($forbiddenRoleB);

        $this->assertTrue($user->hasPermissionTo($permissionB));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasForbiddenPermission($permissionA));
        $this->assertFalse($user->hasPermissionTo($permissionA));
    }

    public function testWildcardAllowsAndDeniesArePartitionIsolated(): void
    {
        $user = GlobalPartitionUser::create(['email' => 'global@example.com']);
        $wildcardA = PartitionedPermission::create(['name' => 'posts.*']);
        $user->givePermissionTo($wildcardA);

        $this->assertTrue($user->hasPermissionTo('posts.create'));
        $this->assertTrue($user->hasPermissionTo('posts.delete.123'));

        $this->setPartition(self::PARTITION_B);
        $wildcardB = PartitionedPermission::create(['name' => 'posts.*']);
        $user->giveForbiddenTo($wildcardB);

        $this->assertFalse($user->hasPermissionTo('posts.create'));
        $this->assertFalse($user->hasPermissionTo('posts.delete.123'));

        $this->setPartition(self::PARTITION_A);

        $this->assertTrue($user->hasPermissionTo('posts.create'));
        $this->assertTrue($user->hasPermissionTo('posts.delete.123'));
    }

    public function testPermissionScopesUseOnlyCurrentPartitionEffects(): void
    {
        $allowed = GlobalPartitionUser::create(['email' => 'allowed@example.com']);
        $denied = GlobalPartitionUser::create(['email' => 'denied@example.com']);
        $permissionA = PartitionedPermission::create(['name' => 'articles.edit']);
        $allowed->givePermissionTo($permissionA);
        $denied->giveForbiddenTo($permissionA);

        $this->assertSame([$allowed->getKey()], GlobalPartitionUser::permission($permissionA)->pluck('id')->all());
        $this->assertSame([$denied->getKey()], GlobalPartitionUser::withoutPermission($permissionA)->pluck('id')->all());

        $this->setPartition(self::PARTITION_B);
        $permissionB = PartitionedPermission::create(['name' => 'articles.edit']);
        $allowed->giveForbiddenTo($permissionB);
        $denied->givePermissionTo($permissionB);

        $this->assertSame([$denied->getKey()], GlobalPartitionUser::permission($permissionB)->pluck('id')->all());
        $this->assertSame([$allowed->getKey()], GlobalPartitionUser::withoutPermission($permissionB)->pluck('id')->all());

        $this->setPartition(self::PARTITION_A);

        $this->assertSame([$allowed->getKey()], GlobalPartitionUser::permission($permissionA)->pluck('id')->all());
    }
}
